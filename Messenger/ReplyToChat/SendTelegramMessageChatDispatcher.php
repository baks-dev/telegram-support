<?php
/*
 * Copyright 2026.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Telegram\Support\Messenger\ReplyToChat;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Messenger\SupportMessage;
use BaksDev\Support\Repository\SupportCurrentEvent\CurrentSupportEventInterface;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusClose;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Support\Type\TelegramChatProfileType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendTelegramMessageChatDispatcher
{
    public function __construct(
        #[Target('telegramSupportLogger')] private LoggerInterface $logger,
        private MessageDispatchInterface $messageDispatch,
        private CurrentSupportEventInterface $CurrentSupportEventRepository,
        private TelegramSendMessages $sendMessageRequest,
        private DeduplicatorInterface $Deduplicator,
    ) {}

    /**
     * При ответе на пользовательские сообщения:
     * - получаем текущее событие чата;
     * - проверяем статус чата - наши ответы закрывают чат - реагируем на статус SupportStatusClose;
     * - отправляем последнее добавленное сообщение - наш ответ;
     * - в случае неудачной отправкио повторяем текущий процесс через интервал времени.
     */
    public function __invoke(SupportMessage $message): void
    {
        $Deduplicator = $this->Deduplicator
            ->namespace('support')
            ->deduplication([$message->getId(), self::class]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $supportEvent = $this->CurrentSupportEventRepository
            ->forSupport($message->getId())
            ->find();

        if(false === ($supportEvent instanceof SupportEvent))
        {
            $this->logger->critical(
                'Ошибка получения события по идентификатору :'.$message->getId(),
                [self::class.':'.__LINE__],
            );

            return;
        }


        /**
         * Пропускаем если тикет не является Telegram Support Chat
         */
        if(false === $supportEvent->isTypeEquals(TelegramChatProfileType::TYPE))
        {
            $Deduplicator->save();
            return;
        }


        /**
         * Ответ только на закрытый тикет
         */
        if(false === ($supportEvent->isStatusEquals(SupportStatusClose::class)))
        {
            return;
        }


        /** @var SupportDTO $supportDTO */
        $SupportDTO = $supportEvent->getDto(SupportDTO::class);
        $supportInvariableDTO = $SupportDTO->getInvariable();

        if(is_null($supportInvariableDTO))
        {
            return;
        }

        /** @var SupportMessageDTO $message */
        $SupportMessageDTO = $supportDTO->getMessages()->last();

        // проверяем наличие внешнего ID - для наших ответов его быть не должно
        if($SupportMessageDTO->getExternal() !== null)
        {
            return;
        }

        $messageText = $SupportMessageDTO->getMessage();

        $isSendMessage = $this->sendMessageRequest
            ->chanel($supportInvariableDTO->getTicket())
            ->message($messageText)
            ->send();

        if(false === $isSendMessage)
        {
            $this->logger->critical(
                sprintf(
                    'telegram-support: Ошибка при отправке сообщения в чат %s. Пробуем повторить позже',
                    $supportInvariableDTO->getTicket(),
                ),
                [self::class.':'.__LINE__, var_export($message, true)],
            );

            $this->messageDispatch
                ->dispatch(
                    message: $message,
                    stamps: [new MessageDelay('30 seconds')],
                    transport: 'telegram-support',
                );
        }
    }
}