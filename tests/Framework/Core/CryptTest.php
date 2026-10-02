<?php
/*
 GarlicSignage: Open Source Digital Signage Stack

 Copyright (C) 2026 Nikolaos Sagiadinos <garlic@saghiadinos.de>
 This file is part of the GarlicSignage source code

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU Affero General Public License, version 3,
 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU Affero General Public License for more details.

 You should have received a copy of the GNU Affero General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
declare(strict_types=1);


namespace Tests\Framework\Core;

use App\Framework\Core\Crypt;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class CryptTest extends TestCase
{
    private Crypt $crypt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypt = new Crypt();
    }

    /**
     * @throws \Exception
     */
    #[Group('units')]
    public function testGenerateRandomString(): void
    {
        $length = 64;
        $randomString = $this->crypt->generateRandomString($length / 2);

        static::assertEquals($length, strlen($randomString));
    }

    /**
     * @throws \Exception
     */
    #[Group('units')]
    public function testGenerateRandomStringWithZeroLength(): void
    {
        $length = 64;
        $randomString = $this->crypt->generateRandomString(0);

        static::assertEquals($length, strlen($randomString));
    }


    /**
     * @throws \Exception
     */
    #[Group('units')]
    public function testGeneratePassword(): void
    {
        $length = 8;
        $password = $this->crypt->generatePassword($length);

        static::assertEquals($length, strlen($password));
    }

    /**
     * @throws \Exception
     */
    #[Group('units')]
    public function testGenerateRandomNumber(): void
    {
        $places = 5;
        $randomNumber = $this->crypt->generateRandomNumber($places);

        static::assertGreaterThanOrEqual(10000, $randomNumber);
        static::assertLessThanOrEqual(99999, $randomNumber);
    }

    #[Group('units')]
    public function testCreatePasswordHashAndCheckPassword(): void
    {
        $password = 'securePassword123!';
        $hash = $this->crypt->createPasswordHash($password);

        static::assertTrue($this->crypt->checkPassword($password, $hash));
        static::assertFalse($this->crypt->checkPassword('wrongPassword', $hash));
    }

    #[Group('units')]
    public function testCreateSha256Hash(): void
    {
        $input = 'test';
        $hash = $this->crypt->createSha256Hash($input);

        static::assertEquals(64, strlen($hash)); // SHA-256 creates 64 chars
    }

    #[Group('units')]
    public function testCreateMd5Hash(): void
    {
        $input = 'test';
        $hash = $this->crypt->createMd5Hash($input);

        static::assertEquals(32, strlen($hash)); // md5 creates 32 chars
    }

    #[Group('units')]
    public function testCreateCrc32vHash(): void
    {
        $input = 'test';
        $hash = $this->crypt->createCrc32bHash($input);

        static::assertGreaterThan(0, strlen($hash));
    }

}
