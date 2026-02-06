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

use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateTimeImmutable;

final readonly class GetTelegramCustomerMessageMessage
{
    /**
     * Идентификатор сообщения пользователя
     */
    private string $id;

    /**
     * Идентификатор чата
     */
    private string $chatId;

    /**
     * Текст сообщения
     */
    private string $text;

    /**
     * Дата создания сообщения
     */
    private DateTimeImmutable $created;

    /**
     * Имя пользователя
     */
    private string $userName;

    /**
     * Идентификатор профиля пользователя
     */
    private string $profile;

    public function __construct(
        string $id,
        string $chatId,
        string $text,
        DateTimeImmutable $created,
        string $userName,
        UserProfileUid|string $profile
    )
    {
        $this->id = $id;
        $this->chatId = $chatId;
        $this->text = $text;
        $this->created = $created;
        $this->userName = $userName;
        $this->profile = (string) $profile;
    }

    public function getProfile(): UserProfileUid
    {
        return new UserProfileUid($this->profile);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreated(): DateTimeImmutable
    {
        return $this->created;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }
}