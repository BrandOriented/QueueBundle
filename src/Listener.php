<?php

namespace IdeasBucket\QueueBundle;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Class Listener
 *
 * @package IdeasBucket\QueueBundle
 */
class Listener
{
    /**
     * The command working path.
     *
     * @var string
     */
    protected $commandPath;

    /**
     * The environment the workers should run under.
     *
     * @var string
     */
    protected $environment;

    /**
     * The amount of seconds to wait before polling the queue.
     *
     * @var int
     */
    protected $sleep = 3;

    /**
     * The amount of times to try a job before logging it failed.
     *
     * @var int
     */
    protected $maxTries = 0;

    /**
     * The queue worker command line.
     *
     * @var string
     */
    protected $workerCommand;

    /**
     * The output handler callback.
     *
     * @var \Closure|null
     */
    protected $outputHandler;

    /**
     * Create a new queue listener.
     *
     * @param  string $commandPath
     */
    public function __construct($commandPath)
    {
        $this->commandPath = $commandPath;
        $this->workerCommand = $this->buildCommandTemplate();
    }

    /**
     * Build the environment specific worker command.
     *
     * @return string
     */
    protected function buildCommandTemplate()
    {
        $command = 'idb_queue:work %s --once --queue=%s --delay=%s --memory=%s --sleep=%s --tries=%s';

        return "{$this->consoleBinary()} {$command}";
    }

    /**
     * Get the PHP binary.
     *
     * @return string
     */
    protected function phpBinary()
    {
        return self::escapeArgument((new PhpExecutableFinder)->find(false));
    }
    private static function isSurroundedBy($arg, $char)
    {
        return 2 < strlen($arg) && $char === $arg[0] && $char === $arg[strlen($arg) - 1];
    }

    public static function escapeArgument($argument)
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            if ('' === $argument) {
                return escapeshellarg($argument);
            }

            $escapedArgument = '';
            $quote = false;
            foreach (preg_split('/(")/', $argument, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if ('"' === $part) {
                    $escapedArgument .= '\\"';
                } elseif (self::isSurroundedBy($part, '%')) {

                    $escapedArgument .= '^%"'.substr($part, 1, -1).'"^%';
                } else {

                    if ('\\' === substr($part, -1)) {
                        $part .= '\\';
                    }
                    $quote = true;
                    $escapedArgument .= $part;
                }
            }
            if ($quote) {
                $escapedArgument = '"'.$escapedArgument.'"';
            }

            return $escapedArgument;
        }

        return "'".str_replace("'", "'\\''", $argument)."'";
    }

    /**
     * Get the console binary.
     *
     * @return string
     */
    protected function consoleBinary()
    {
        return realpath(__DIR__.'/../../../../bin/console');
    }

    /**
     * Listen to the given queue connection.
     *
     * @param  string          $connection
     * @param  string          $queue
     * @param  ListenerOptions $options
     */
    public function listen($connection, $queue, ListenerOptions $options)
    {
        $process = $this->makeProcess($connection, $queue, $options);

        while (true) {

            $this->runProcess($process, $options->memory);
        }
    }

    /**
     * Create a new Symfony process for the worker.
     *
     * @param  string          $connection
     * @param  string          $queue
     * @param  ListenerOptions $options
     *
     * @return \Symfony\Component\Process\Process
     */
    public function makeProcess($connection, $queue, ListenerOptions $options)
    {
        $command = $this->workerCommand;

        // If the environment is set, we will append it to the command string so the
        // workers will run under the specified environment. Otherwise, they will
        // just run under the production environment which is not always right.
        if (isset($options->environment)) {

            $command = $this->addEnvironment($command, $options);
        }

        // Next, we will just format out the worker commands with all of the various
        // options available for the command. This will produce the final command
        // line that we will pass into a Symfony process object for processing.
        $command = $this->formatCommand($command, $connection, $queue, $options);

        return new Process(explode(" ", $command), $this->commandPath, null, null, $options->timeout);
    }

    /**
     * Add the environment option to the given command.
     *
     * @param  string          $command
     * @param  ListenerOptions $options
     *
     * @return string
     */
    protected function addEnvironment($command, ListenerOptions $options)
    {
        return $command . ' --env=' . self::escapeArgument($options->environment);
    }

    /**
     * Format the given command with the listener options.
     *
     * @param  string          $command
     * @param  string          $connection
     * @param  string          $queue
     * @param  ListenerOptions $options
     *
     * @return string
     */
    protected function formatCommand($command, $connection, $queue, ListenerOptions $options)
    {
        return sprintf(
            $command,
            self::escapeArgument($connection),
            self::escapeArgument($queue),
            $options->delay, $options->memory,
            $options->sleep, $options->maxTries
        );
    }

    /**
     * Run the given process.
     *
     * @param  \Symfony\Component\Process\Process $process
     * @param  int                                $memory
     */
    public function runProcess(Process $process, $memory)
    {
        $process->run(function ($type, $line) {

            $this->handleWorkerOutput($type, $line);
        });

        // Once we have run the job we'll go check if the memory limit has been exceeded
        // for the script. If it has, we will kill this script so the process manager
        // will restart this with a clean slate of memory automatically on exiting.
        if ($this->memoryExceeded($memory)) {

            $this->stop();
        }
    }

    /**
     * Handle output from the worker process.
     *
     * @param  int    $type
     * @param  string $line
     */
    protected function handleWorkerOutput($type, $line)
    {
        if (isset($this->outputHandler)) {

            call_user_func($this->outputHandler, $type, $line);
        }
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param  int $memoryLimit
     *
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage() / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     */
    public function stop()
    {
        die;
    }

    /**
     * Set the output handler callback.
     *
     * @param  \Closure $outputHandler
     */
    public function setOutputHandler(\Closure $outputHandler)
    {
        $this->outputHandler = $outputHandler;
    }
}
