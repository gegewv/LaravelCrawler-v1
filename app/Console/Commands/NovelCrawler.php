<?php

namespace App\Console\Commands;

use App\Article;
use App\Novel;
use App\Task;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use function GuzzleHttp\Psr7\str;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

class NovelCrawler extends Command
{
    protected $signature = 'novel:crawler';
    protected $description = '小说爬取';

    protected $novel_online = 'http://www.5hzw.com';
    protected $novel_name = '落地一把98K';     // 绝地求生之最强主播
    protected $novel_url = 'http://www.5hzw.com/4_4787/';      // http://www.5hzw.com/17_17857/
    protected $novel;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // 查询此小说是否加入
        $this->check();
        $this->crawling_article();

        while (true) {
            $task_model = $this->task();

            // 如果没有爬取计划， 则结束爬取
            if (empty($task_model)) {
                return $this->info("Crawler End");
            }

            // 爬取文章内容
            $this->crawling_content($task_model);
        }
    }

    // 爬取小说章节
    private function crawling_article()
    {
        $url = $this->novel_url;
        $html = $this->send_http($url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $crawler->filter('#list')->filter('dl > dd > a')->each(function (Crawler $node, $i) use ($url) {
            $text = $this->stringInterception($node->text());
            $href = $this->novel_online . $node->attr('href');

            $article = [
                'novel_id' => $this->novel->id,
                'title' => $text,
                'url' => $href,
                'content' => ''
            ];
            $this->article($article);
        });
    }

    // 爬取内容, 并设置爬取状态为 2
    private function crawling_content($task)
    {
        $html = $this->send_http($task->url);

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'gb18030');
        $content = $crawler->filter('#content')->text();

        $task->content = $content;
        $task->status = 2;
        $task->save();
        $this->info($task->title);
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
            $res = $client->request('GET', $url);
            $html = (string)$res->getBody();
        }

        return $html;
    }

    // 更新爬取状态, 设置为进行中
    private function task($status = 0)
    {
        $task = Article::where("status", $status)->first();
        if ($task) {
            $task->status = 1;  // 进行中
            $task->save();
        }
        return $task;
    }

    // 爬取文章
    private function article($data)
    {
        $hasArticle = Article::where([
            ['novel_id', $data['novel_id']],
            ['title', $data['title']]
        ])->first();
        if (!$hasArticle) {
            Article::create($data);
        }
    }

    // 查询此小说是否加入
    private function check()
    {
        $hasNovel = Novel::where('title', $this->novel_name)->first();

        if ($hasNovel) {
            $this->novel = $hasNovel;
        } else {
            $this->novel = new Novel();
            $this->novel->title = $this->novel_name;
            $this->novel->data = $this->novel_name;
            $this->novel->save();
        }
        $this->info('ID: ' . $this->novel->id . ' - 标题: ' . $this->novel->title);
        $this->crawling_article();
    }

    // 去除小说标题中的  "（*********）"
    function stringInterception($str)
    {
        $array = explode('（', $str);
        return $array[0];
    }

    // 重写 info 方法
//    public function info($string, $verbosity = null)
//    {
//        $string = iconv( 'UTF-8', 'GB18030', $string); // cmd 中文gbk编码
//        parent::line($string, 'info', $verbosity);
//    }
}
