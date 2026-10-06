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

namespace App\Modules\PlayLog;

use App\Framework\Query\FilterParameters;
use App\Framework\Query\FilterValidatorInterface;
use App\Framework\Validation\FieldValidator;

/**
 * The parameters of GET /v1/playlog/stats besides the common ones: sort (content_id, plays or duration_s,
 * default content_id) and the optional content_id. The filters always contain the sort.
 */
readonly class PlayLogStatsFilterValidator implements FilterValidatorInterface
{
    public const array SORTS = ['content_id', 'plays', 'duration_s'];

    private const int MAX_LENGTH_ID = 128;

    public function __construct(private FieldValidator $fields) {}

    /**
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors
     * @return array<string,string>
     */
    public function validate(array $params, array &$errors): array
    {
        $sort = $params['sort'] ?? 'content_id';
        if (!is_string($sort) || !in_array($sort, self::SORTS, true))
        {
            $errors['sort'] = 'must be one of ' . implode(', ', self::SORTS);
            $sort           = 'content_id';
        }

        $filters   = ['sort' => $sort];
        $contentId = FilterParameters::optionalString($this->fields, $params, 'content_id', self::MAX_LENGTH_ID, $errors);
        if ($contentId !== null)
            $filters['content_id'] = $contentId;

        return $filters;
    }
}
