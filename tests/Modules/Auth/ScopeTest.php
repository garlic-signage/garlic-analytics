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

namespace Tests\Modules\Auth;

use App\Modules\Auth\ApiClient;
use App\Modules\Auth\Scope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    #[Group('units')]
    public function testReadingMethodsNeedRead(): void
    {
        static::assertSame(Scope::Read, Scope::forMethod('GET'));
        static::assertSame(Scope::Read, Scope::forMethod('head'));
    }

    #[Group('units')]
    public function testWritingMethodsNeedIngest(): void
    {
        static::assertSame(Scope::Ingest, Scope::forMethod('POST'));
        static::assertSame(Scope::Ingest, Scope::forMethod('PUT'));
        static::assertSame(Scope::Ingest, Scope::forMethod('DELETE'));
    }

    #[Group('units')]
    public function testClientHasOnlyGrantedScopes(): void
    {
        $client = new ApiClient('collector', [Scope::Ingest]);

        static::assertTrue($client->hasScope(Scope::Ingest));
        static::assertFalse($client->hasScope(Scope::Read));
    }
}
