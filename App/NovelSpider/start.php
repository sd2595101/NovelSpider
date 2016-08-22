<?php
require __DIR__."/../../vendor/autoload.php";

use \Workerman\Worker;
use \Workerman\Lib\Timer;
use \Workerman\Connection\AsyncTcpConnection;

use QL\QueryList;
use Novel\NovelSpider\Controller\Test;
use Predis\Client;
use Novel\NovelSpider\Models\ListModel;
use Novel\NovelSpider\Models\ContentModel;

$task = new Worker();
// 开启多少个进程运行定时任务，注意多进程并发问题
$task->count = 1;
$task->onWorkerStart = function($task) {
    $keyConfig = [
        'list-key'=>'novel-list-key',
        'detail-key'=>'novel-detail-key',
    ];
    // 重新 获取小说列表
//    $novel = new Test();
//    $flag = $novel->saveList();

    // 只在id编号为0的进程上设置定时器，其它1、2、3号进程不设置定时器
    if($task->id === 0){
        echo "worker 0 start for list~".PHP_EOL;
        $lisrModel = new ListModel();
        $novel = new Test();
        $conModel = new ContentModel();
        $count = 0;
        // 开启一个内部端口，方便内部系统推送数据，Text协议格式 文本+换行符
        $taskConnetion = new AsyncTcpConnection('Text://127.0.0.1:3001');
        // 异步获得结果
        $taskConnetion->onMessage = function($taskConnetion, $taskesult) use ($novel,$conModel,$task,$count)
        {
            // 结果
            //var_dump($taskesult);
            $res = $novel->getDetail($taskesult);
            if(!$res)$res=0;
            $errFlag = $res ? 0 : 1;
            $data = [
                'list_id'=>2,
                'chapter'=>$res['chapter'],
                'title'=>$res['title'],
                'content'=>$res['content'],
                'worker_id'=>$task->id,
                'date'=>date('Y-m-d H:i:s'),
                'err_flag'=>$errFlag,
            ];
            $conModel->insertData($data);
            echo "任务".$task->id.'->'.$count."完成~~~~~~~~~~~~".$count.PHP_EOL;
        };
        $taskData = $novel->getTaskDataFromProcess($taskConnetion);
        while($taskData){
            $taskData = $novel->getTaskDataFromProcess($taskConnetion);
        }
        // 获得结果后记得关闭异步链接
        $taskConnetion->close();

        // 将列表存进redis存放
        // 从redis取出数据
        //$redis = new Predis\Client();
        //$res = $redis->hgetall($keyConfig['list-key']);
        //$res = $novel->getListFromRedis($keyConfig['list-key']);
        // 读取redis队列,去爬取详情
        // 定时请求,保证获取最新
        $time_interval = 3600*1.5;
        Timer::add($time_interval, function(){
            echo "task run\n";
            // 获取最新的最后一个url,查看是否与mysql中的最新的url,是否一致,不一致,则把最新的url等数据加入mysql
        });
    } else if($task->id >= 1){ // 进程1号 抓取详情
        echo "worker ".$task->id." start for detail~".PHP_EOL;
        // 抓取详情
        $novel = new Test();
        $conModel = new ContentModel();
        $i = 0;
        do{
            $res = $novel->getDetail(2);
            if(!$res)continue;
            $errFlag = $res ? 0 : 1;
            $data = [
                'list_id'=>2,
                'chapter'=>$res['chapter'],
                'title'=>$res['title'],
                'content'=>$res['content'],
                'worker_id'=>$task->id,
                'date'=>date('Y-m-d H:i:s'),
                'err_flag'=>$errFlag,
            ];
            $conModel->insertData($data);
            echo "任务".$task->id.'->'.$i."完成~~~~~~~~~~~~".$i.PHP_EOL;
            $i++;
        }while($res);

        echo "detail finished~".PHP_EOL;

    }// end of if


};

// 运行worker
Worker::runAll();