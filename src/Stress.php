<?php


namespace Actor\Stress;


use Doctrine\Common\Collections\ArrayCollection;
use Swoole\Coroutine;
use Swoole\Timer;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use function Swoole\Coroutine\run;


class Stress
{
    private $options;

    private $type;

    private $results;

    public function __construct(array $options, int $type)
    {
        $this->options = $options;

        $this->type;
    }

    /**
     * @param OutputInterface $output
     * @throws \Exception
     */
    public function start(OutputInterface $output)
    {
        $works = $cpuNum = swoole_cpu_num();
        $concurrency = $this->options['concurrency'];
        $tasks = [];
        if ($concurrency < $cpuNum) {
            $works = 1;
            $tasks[0] = $concurrency;
        }
        $tasks = $this->allocateTask($concurrency, $works);
        $output->writeln("");
        $output->writeln("本次派出" . $works . "个进程,共" . $concurrency . "个协程对接口进行疯狂轰炸!!!");
        $output->writeln("");
        $output->writeln("请求地址：" . $this->options['full_url']);
        $output->writeln("");
        $output->writeln("并发数：" . $this->options['concurrency']);
        $output->writeln("");
        $output->writeln("请求数：" . $this->options['request']);
        $output->writeln("");

        for ($i = 0; $i < $works; $i++) {
            $process = new Process($this->options, $tasks, $i);
            $process->start();
        }
        run(function () use ($output) {
            $resultChannel = new Coroutine\Channel($this->options['concurrency'] * $this->options['request']);
            foreach (Process::$processMap as $processId => $process) {
                Coroutine::create(function () use ($process, $resultChannel) {
                    $socket = $process->exportSocket();
                    while (true) {
                        $recv = $socket->recv();
                        if ($recv == 'over') {
                            return;
                        }
                        $resultChannel->push(true);
                        $this->results[] = json_decode($recv);
                    }
                });
            }
            $table = new Table($output);
            $table->setColumnWidths([0 => 5, 1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5]);
            $table->addRow(['耗时', '并发数', '成功数', '失败数', 'QPS', '平均耗时']);
            $table->render();
            Timer::tick(1000, function ($timerId) use ($table, $resultChannel) {
                if ($resultChannel->isFull()) {
                    Timer::clear($timerId);
                }
                if (!empty($this->results)) {
                    $table->setRows([$this->calculate()]);
                    $table->render();
                }

            });
        });
        \Swoole\Process::wait(true);
        $output->writeln("");
        $final = $this->calculate();
        $output->writeln(sprintf("战况：共派出%d个协程，发起总请求数：%d，成功请求数：%d,失败请求：%d，QPS：%s，平均耗时：%s", $concurrency, $concurrency * $this->options['request'], $final[2], $final[3], $final[4], $final[5]));
        $output->writeln("");
    }

    private function calculate(): array
    {
        static $count = 0;
        static $flag = 0;
        static $totalTime = 0;
        static $success = 0;
        static $fails = 0;
        for (; $flag < count($this->results); $flag++) {
            $requestModel = $this->results[$flag];
            $totalTime = bcadd($totalTime, $requestModel->spendTime, 4);
            if ($requestModel->success) {
                $success++;
            } else {
                $fails++;
            }
        }
        $qps = bcdiv(count($this->results), $totalTime, 2);
        $avgTime = bcdiv($totalTime, count($this->results), 4);
        return [$count++, $this->options['concurrency'], $success, $fails, $qps . '/秒', $avgTime . '秒'];
    }

    /**
     * 根据并发数和进程数分配任务 每个进程处理多少并发 平均分配
     * @param int $concurrency
     * @param int $works
     * @return array
     */
    private function allocateTask(int $concurrency, int $works): array
    {
        $task = [];
        $remains = $concurrency % $works;
        $per = floor($concurrency / $works);
        for ($i = 0; $i < $works; $i++) {
            $task[$i] = $per;
        }
        if ($remains) {
            for ($j = 0; $j < $remains; $j++) {
                $task[$j]++;
            }
        }
        return $task;
    }
}