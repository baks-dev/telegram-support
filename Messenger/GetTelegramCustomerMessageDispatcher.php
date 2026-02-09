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

namespace BaksDev\Telegram\Support\Messenger;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Auth\Telegram\Repository\ActiveUserTelegramAccount\ActiveUserTelegramAccountInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Support\Entity\Event\SupportEvent;
use BaksDev\Support\Entity\Support;
use BaksDev\Support\Repository\FindExistMessage\FindExistExternalMessageByIdInterface;
use BaksDev\Support\Repository\SupportCurrentEventByTicket\CurrentSupportEventByTicketInterface;
use BaksDev\Support\Type\Status\SupportStatus;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusOpen;
use BaksDev\Support\UseCase\Admin\New\Invariable\SupportInvariableDTO;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Support\UseCase\Admin\New\SupportHandler;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramBotCommands;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use BaksDev\Telegram\Support\Type\TelegramChatProfileType;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Repository\UserTokenStorage\UserTokenStorageInterface;
use BaksDev\Users\User\Type\Id\UserUid;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


/**
 * Получает новые сообщения из чатов бота с пользователями Telegram
 */
#[AsMessageHandler(priority: -998)]
final readonly class GetTelegramCustomerMessageDispatcher
{
    public function __construct(
        private DeduplicatorInterface $Deduplicator,
        private CurrentSupportEventByTicketInterface $CurrentSupportEventByTicketRepository,
        private FindExistExternalMessageByIdInterface $FindExistExternalMessageByIdRepository,
        private SupportHandler $SupportHandler,
        private ActiveProfileByAccountTelegramInterface $ActiveProfileByAccountTelegramRepository,
        private UserTokenStorageInterface $UserTokenStorage,
        private ActiveUserTelegramAccountInterface $ActiveUserTelegramAccount,
        #[Target('telegramSupportLogger')] private LoggerInterface $Logger,
        #[Autowire(env: 'PROJECT_PROFILE')] private ?string $projectProfile = null,
    ) {}

    public function __invoke(TelegramEndpointMessage $message): void
    {
        $telegramRequest = $message->getTelegramRequest();


        /** Проверка на тип запроса */
        if(false === $telegramRequest instanceof TelegramRequestMessage)
        {
            return;
        }


        /** При совпадении с установленными триггерами - пропускаем обработку */
        if(in_array($telegramRequest->getText(), TelegramBotCommands::allCommands()))
        {
            return;
        }


        $Deduplicator = $this->Deduplicator
            ->namespace('telegram-support')
            ->deduplication([$telegramRequest->getId(), self::class]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        /** Если такое сообщение уже есть в БД, то пропускаем */
        $messageExist = $this->FindExistExternalMessageByIdRepository
            ->external($telegramRequest->getId())
            ->exist();


        if($messageExist)
        {
            $Deduplicator->save();
            return;
        }


        /** Находим пользователя */
        $userUid = $this->ActiveUserTelegramAccount
            ->findByChat($telegramRequest->getChatId());

        /** Если пользователь не был найден - пропускаем */
        if(false === ($userUid instanceof UserUid))
        {
            $this->Logger->warning('Идентификатор авторизованного пользователя не найден', [
                __FILE__.' '.__LINE__,
                'chat' => $telegramRequest->getChatId(),
            ]);

            return;
        }


        /** Находим профиль пользователя */
         $userProfileUid = $this->ActiveProfileByAccountTelegramRepository
            ->findByChat($telegramRequest->getChatId());

        /** Если у пользователя нет активного профиля - пропускаем */
        if(null ===  $userProfileUid)
        {
            $this->Logger->warning('Идентификатор профиля авторизованного пользователя не найден', [
                __FILE__.' '.__LINE__,
                'chat' => $telegramRequest->getChatId(),
            ]);

            return;
        }


        /**
         * Авторизуем пользователя для лога изменений если сообщение обрабатывается из очереди
         */
        $this->UserTokenStorage->authorization($userUid);


        $ticket = $telegramRequest->getChatId();

        /** Если такой тикет уже существует в БД, то присваиваем в переменную $SupportEvent */
        $supportEvent = $this->CurrentSupportEventByTicketRepository
            ->forTicket($ticket)
            ->find();


        $supportDTO = true === ($supportEvent instanceof SupportEvent)
            ? $supportEvent->getDto(SupportDTO::class)
            : new SupportDTO(); // done


        /** Присваиваем значения по умолчанию для нового тикета */
        if(false === ($supportEvent instanceof SupportEvent))
        {
            $profile = false === empty($this->projectProfile) ? new UserProfileUid($this->projectProfile) : $this->projectProfile;


            /**
             * SupportInvariable
             */
            $supportInvariableDTO = new SupportInvariableDTO();
            $supportInvariableDTO
                ->setProfile($profile)
                ->setType(new TypeProfileUid(TelegramChatProfileType::TYPE))
                ->setTicket($ticket);


            /** Устанавливаем заголовок чата из сообщения */
            $title = mb_strimwidth($telegramRequest->getText(), 0, 255);


            /** Присваиваем заголовок тикета */
            $supportInvariableDTO->setTitle($title);

            $supportDTO->setInvariable($supportInvariableDTO);


            /** Присваиваем токен для последующего ответа */
            $supportDTO->getToken()->setValue( $userProfileUid);
        }


        // при добавлении нового сообщения открываем чат заново
        $supportDTO->setStatus(new SupportStatus(SupportStatusOpen::class));


        // подготовка DTO для нового сообщения
        $supportMessageDTO = new SupportMessageDTO();
        $supportMessageDTO
            ->setMessage($telegramRequest->getText())
            ->setDate(new DateTimeImmutable()->setTimestamp($telegramRequest->getDate()))
            ->setExternal($telegramRequest->getId()) // идентификатор сообщения в Telegram
            ->setName($telegramRequest->getUser()->getFirstName().' '.$telegramRequest->getUser()->getLastName())
            ->setInMessage();

        $supportDTO->addMessage($supportMessageDTO);

        $handle = $this->SupportHandler->handle($supportDTO);

        if(false === $handle instanceof Support)
        {
            $this->Logger->critical(
                sprintf('telegram-support: Ошибка %s при создании/обновлении чата поддержки', $handle),
                [
                    self::class.':'.__LINE__,
                    $supportDTO->getInvariable()?->getTicket(),
                ],
            );
        }

        $Deduplicator->save();

        $message->complete();
    }
}