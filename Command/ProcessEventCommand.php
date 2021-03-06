<?php

namespace Itkg\DelayEventBundle\Command;

use Itkg\DelayEventBundle\Handler\LockHandlerInterface;
use Itkg\DelayEventBundle\Model\Event;
use Itkg\DelayEventBundle\Processor\EventProcessor;
use Itkg\DelayEventBundle\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ProcessEventCommand
 */
class ProcessEventCommand extends ContainerAwareCommand
{
    /**
     * @var EventRepository
     */
    private $eventRepository;

    /**
     * @var EventProcessor
     */
    private $eventProcessor;

    /**
     * @var LockHandlerInterface
     */
    private $lockHandler;

    /**
     * @var array
     */
    private $channels;

    /**
     * ProcessEventCommand constructor.
     *
     * @param EventRepository      $eventRepository
     * @param EventProcessor       $eventProcessor
     * @param LockHandlerInterface $lockHandler
     * @param array                $channels
     * @param null|string          $name
     */
    public function __construct(
        EventRepository $eventRepository,
        EventProcessor $eventProcessor,
        LockHandlerInterface $lockHandler,
        array $channels = [],
        $name = null
    ) {
        $this->eventRepository = $eventRepository;
        $this->eventProcessor = $eventProcessor;
        $this->lockHandler = $lockHandler;
        $this->channels = $channels;

        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('itkg_delay_event:process')
            ->setDescription('Process async events')
            ->addOption(
                'channel',
                'c',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Specify the channels to process (default: [\'default\'])',
                ['default']
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $channels = $input->getOption('channel');

        foreach ($channels as $channel) {
            if (!isset($this->channels[$channel])) {
                $output->writeln(
                    sprintf(
                        '<error>Channel <info>%s</info> is not configured.</error>',
                        $channel
                    )
                );

                continue;
            }

            if ($this->lockHandler->isLocked($channel)) {
                $output->writeln(
                    sprintf(
                        'Command is locked by another process for channel <info>%s</info>.',
                        $channel
                    )
                );

                continue;
            }

            $output->writeln(
                sprintf(
                    'Process events for channel <info>%s</info>',
                    $channel
                )
            );

            $this->lockHandler->lock($channel);

            $processedEventsCount = 0;
            $maxProcessedQueueSize = $this->channels[$channel]['events_limit_per_run'];
            $event = null;

            try {
                while (
                    $maxProcessedQueueSize === null
                    || $processedEventsCount < $maxProcessedQueueSize
                ) {
                    if (!$this->lockHandler->isLocked($channel)) {
                        $output->writeln(
                            sprintf(
                                '<error>Lock for channel <info>%s</info> has been released outside of the process.</error>',
                                $channel
                            )
                        );

                        break;
                    }

                    $event = $this->eventRepository->findFirstTodoEvent(
                        false,
                        $this->channels[$channel]['include'],
                        $this->channels[$channel]['exclude']
                    );

                    if (!$event instanceof Event) {
                        break;
                    }

                    $event->setDelayed(false);
                    $this->eventProcessor->process($event);
                    $processedEventsCount++;
                }
            } catch (\Exception $e) {
                if ($event instanceof Event) {
                    $output->writeln(
                        sprintf(
                            '<info>[%s]</info> <error> An error occurred while processing event "%s".</error>',
                            $channel,
                            $event->getOriginalName()
                        )
                    );
                }

                $output->writeln(sprintf('<info>[%s]</info> <error>%s</error>', $channel, $e->getMessage()));
                $output->writeln($e->getTraceAsString());
            }

            $this->lockHandler->release($channel);
        }
    }
}
