<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ChatExportProcessor;
use App\Service\RateLimiter;
use App\Service\Telegram\BotService;
use App\Telegram\Command\GenericmessageCommand;
use Longman\TelegramBot\Exception\TelegramException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: "app:bot:polling",
    description: "Запуск Telegram бота в режиме long polling",
)]
class BotPollingCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly BotService $botService,
        private readonly ChatExportProcessor $processor,
        private readonly int $rateLimitMaxFiles,
        private readonly int $rateLimitWindowSeconds,
    ) {
        parent::__construct();
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title("Telegram Bot Polling");
        $io->info("Запуск бота в режиме long polling...");
        $io->info("Нажмите Ctrl+C для остановки");

        try {
            // Устанавливаем processor для GenericmessageCommand
            GenericmessageCommand::setProcessor($this->processor);

            // Конфигурируем rate limiter
            RateLimiter::configure($this->rateLimitMaxFiles, $this->rateLimitWindowSeconds);
            $io->info(sprintf(
                "Rate limit: %d файлов за %d секунд",
                $this->rateLimitMaxFiles,
                $this->rateLimitWindowSeconds
            ));

            $telegram = $this->botService->getTelegram();
            $io->success("Бот инициализирован: @" . $telegram->getBotUsername());

            while (!$this->shouldStop) {
                try {
                    $serverResponse = $telegram->handleGetUpdates();

                    if ($serverResponse->isOk()) {
                        $updates = $serverResponse->getResult();
                        if (count($updates) > 0) {
                            $io->writeln(sprintf(
                                "[%s] Обработано %d обновлений",
                                date("Y-m-d H:i:s"),
                                count($updates)
                            ));
                        }
                    } else {
                        $io->error("Ошибка: " . $serverResponse->getDescription());
                    }

                    // Обрабатываем накопленные media groups
                    GenericmessageCommand::processMediaGroups();

                    // Очистка устаревших записей rate limiter
                    RateLimiter::cleanup();

                } catch (TelegramException $e) {
                    $io->error("Ошибка Telegram: " . $e->getMessage());
                    sleep(5);
                }
            }

            $io->info("Бот остановлен");
            return Command::SUCCESS;

        } catch (TelegramException $e) {
            $io->error("Критическая ошибка инициализации: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
