<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Jobs;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\ArrayInput;
use SilverStripe\PolyExecution\PolyOutput;
use RobIngram\SilverStripe\UnusedFileReport\Tasks\UnusedFileReportBuildTask;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;


if (class_exists(AbstractQueuedJob::class)) {
    /**
     * Allows the optional use of queued jobs module to to run the unused file
     * report builder task. If the module isn't installed, nothing is
     * done - SilverStripe will never include this class declaration.
     *
     * @see https://github.com/symbiote/silverstripe-queuedjobs
     * @author Rob Ingram <robert.ingram@ccc.govt.nz>
     * @package Unused File Report
     */
    class UnusedFileReportJob extends AbstractQueuedJob implements QueuedJob
    {
        /**
         * @return string
         */
        public function getTitle()
        {
            return "Unused File Report Builder Task";
        }

        /**
         * {@inheritDoc}
         * @return string
         */
        public function getJobType()
        {
            $this->totalSteps = 1;
            echo $this->totalSteps;
            return QueuedJob::QUEUED;
        }

        /**
         * {@inheritDoc}
         */
        public function process()
        {
            $task = UnusedFileReportBuildTask::create();
            $definition = new InputDefinition($task->getOptions());
            $input = new ArrayInput([], $definition);
            $output = PolyOutput::create(PolyOutput::FORMAT_ANSI);
            $definition = new InputDefinition($task->getOptions());
            $input = new ArrayInput([], $definition);
            $output = PolyOutput::create(PolyOutput::FORMAT_ANSI);
            $task->run($input, $output);

            $this->currentStep = 1;
            $this->isComplete = true;

            echo $this->isComplete;
        }
    }
}
