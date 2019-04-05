<?php

namespace App\Console\Commands;

use App\Area;
use App\Crawler as CrawlerTask;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use function GuzzleHttp\Psr7\str;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class CityCrawler
 * @package App\Console\Commands
 */
class CityCrawler extends Command
{
    /**
     * 流程：
     * 1. func top 抓取行政区域省级, 每个省链接生成一次抓取任务，保存到任务表crawler
     * 2. 循环抓取
     *  1). 读取一条任务 select * from crawler where status = 0 limit 1; update crawler set status = 1 where id = 本次任务id;
     *  2). 根据任务类型调用抓取方法 如 镇抓取：crawler_towntr 区抓取crawler_districts 城市抓取crawler_citys
     *  3). 抓取方法中保存抓取到的行政区域数据到area表，并根据抓取行政区域下一级生成下一次抓取任务，存放任务表
     */

    protected $signature = 'street:crawler';
    protected $description = 'Command description';

    protected $start_url = 'http://www.stats.gov.cn/tjsj/tjbz/tjyqhdmhcxhfdm/2016/';
    protected $special_city = ['佛山市','中山市','茂名市','广州市','清远市']; // 中国5个不设市辖区的地级市

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->top();

        while(true) {
            $task_model = $this->task();

            // 如果没有爬取计划， 则结束爬取
            if (empty($task_model)) {
                return $this->info("End");
            }
            $task = json_decode($task_model->data, true);

            // 打印日志
            $this->info(implode(',', array_map(function ($item) {
                return $item['id'] . ' ' . $item['name'];
            }, $task['data'])));

            $status = call_user_func(array($this, 'crawler_' . $task['crawler']), $task);
            if ($status) {
                $this->finish($task_model);
            } else {
                var_dump($task, 'error');
                return false;
            }

            $this->info('sleep 1');
            sleep(1);
        }
    }

    // 抓取省级行政区域
    private function top()
    {
        $url = $this->start_url;
        $html = $this->send_http($url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $crawler->filter('.provincetr')->filter('td > a')->each(function (Crawler $node, $i) use ($url) {
            $text = $node->text();
            $href = $node->attr('href');
            $id = str_replace('.html', '', $href);

            $task = [
                'crawler' => 'citys',
                'remark' => '省',
                'url' => substr($url, 0, strrpos($url, '/')) . '/' . $href,
                'data' => [['name' => $text, 'id' => $id]],
                'parent_id' => $id,
            ];
            $this->push($task);

            Area::create(
                [
                    'id' => $id,
                    'name' => $text,
                    'parent_id' => 0
                ]
            );

            $this->info($node->attr('href'));
            $this->info($text);
        });
    }

    public function crawler_towntr($task)
    {

        $url = $task['url'];

        if(!strpos($url, '.html')){
            $this->info('为空的直辖市');
            return true;
        }

        $html = $this->send_http($url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $crawler->filter('.towntr')->each(function(Crawler $node, $i) use ($task, $url) {

            $code_node = $node->filter('td')->eq(0)->filter('a');
            $name_node = $node->filter('td')->eq(1)->filter('a');

            Area::create(
                [
                    'id' => $code_node->text(),
                    'name' => $name_node->text(),
                    'parent_id' => $task['parent_id']
                ]
            );

            $this->info($code_node->text() . '  ' . $name_node->text());
        });

        return true;
    }

    public function crawler_citys($task)
    {
        $url = $task['url'];
        $html = $this->send_http($url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $crawler->filter('.citytr')->each(function(Crawler $node, $i) use ($task, $url) {

            $code_node = $node->filter('td')->eq(0)->filter('a');
            $name_node = $node->filter('td')->eq(1)->filter('a');
            $href = $code_node->attr("href");

            $this->info($code_node->text() . '  ' . $name_node->text());

            Area::create(
                [
                    'id' => $code_node->text(),
                    'name' => $name_node->text(),
                    'parent_id' => $task['parent_id']
                ]
            );


            $data = $task['data'];
            $data[] = ['name' => $name_node->text(), 'id' => $code_node->text()] ;

            if(in_array($name_node->text(), $this->special_city)){
                $new_task = [
                    'crawler' => 'towntr',
                    'remark' => '特别的5个省地级市',
                    'url' => substr($url, 0, strrpos($url, '/')) . '/' . $href,
                    'data' => $data,
                    'parent_id' => $code_node->text(),
                ];
            }else{

                $new_task = [
                    'crawler' => 'districts',
                    'remark' => '城市',
                    'url' => substr($url, 0, strrpos($url, '/')) . '/' . $href,
                    'data' => $data,
                    'parent_id' => $code_node->text(),
                ];

            }


            $this->push($new_task);

        });

        return true;
    }

    public function crawler_districts($task)
    {
        $url = $task['url'];
        $html = $this->send_http($url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $crawler->filter('.countytr')->each(function(Crawler $node, $i) use ($task, $url) {


            $code_node = $node->filter('td')->eq(0)->filter('a');
            $name_node = $node->filter('td')->eq(1)->filter('a');

            //没有子节点
            if($code_node->count() == 0){
                $code_node = $node->filter('td')->eq(0);
                $name_node = $node->filter('td')->eq(1);
            }else{

                $href = $code_node->attr("href");
                $data = $task['data'];
                $data[] = ['name' => $name_node->text(), 'id' => $code_node->text()] ;

                $new_task = [
                    'crawler' => 'towntr',
                    'remark' => '县 区',
                    'url' => substr($url, 0, strrpos($url, '/')) . '/' . $href,
                    'data' => $data,
                    'parent_id' => $code_node->text(),
                ];

                $this->push($new_task);
            }

            $this->info($code_node->text() . '  ' . $name_node->text());

            Area::create(
                [
                    'id' => $code_node->text(),
                    'name' => $name_node->text(),
                    'parent_id' => $task['parent_id']
                ]
            );

        });
        return true;
    }

    // 发送http请求
    private function send_http($url)
    {
        $user_agent_list = [
            'Mozilla/5.0 (Windows NT 6.3; rv:36.0) Gecko/20100101 Firefox/36.04',
            'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.9 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 Safari/537.36 OPR/48.0.2685.52',
            'Mozilla/5.0 (Windows NT 10.0; WOW64; rv:56.0) Gecko/20100101 Firefox/56.0',
            'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/533.20.25 (KHTML, like Gecko) Version/5.0.4 Safari/533.20.27',
            'Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko',
        ];

        $user_agent = $user_agent_list[(time() % 6)];
        $timeout = 5;

        $client = new Client(['headers' => ['User-Agent' => $user_agent], 'timeout' => $timeout]);

        try {
            $res = $client->request('GET', $url);
            $html = (string)$res->getBody();
        } catch (RequestException  $e) {
            // 抓取中会有404状态返回，再重新请求一次
            $this->info(str($e->getRequest()));
            if ($e->hasResponse()) {
                $this->info(str($e->getResponse()));
            }

            $this->info("send_http timeout retry");
            $this->info("sleep 2s");
            sleep(2);
            $res  = $client->request('GET', $url);
            $html =  (string)$res->getBody();
        }

        return $html;
    }

    // 重写 info 方法
    public function info($string, $verbosity = null)
    {
        $string = iconv( 'UTF-8', 'GB18030', $string); // cmd 中文gbk编码
        parent::line($string, 'info', $verbosity);
    }

    // 存储爬取记录
    private function push($data)
    {
        $task = new CrawlerTask;
        $task->data = json_encode($data);
        $task->save();
    }

    // 更新爬取记录状态, 设置为进行中
    private function task($status = 0)
    {
        $task = CrawlerTask::where("status", $status)->first();
        $task->status = 1;  // 进行中
        $task->save();
        return $task;
    }

    // 更新爬取记录状态, 设置为完成
    private function finish($task)
    {
        $task->status = 2;
        $task->save();
    }
}
