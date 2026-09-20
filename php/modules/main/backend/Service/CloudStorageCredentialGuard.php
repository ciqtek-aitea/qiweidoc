<?php

namespace Modules\Main\Service;

use LogicException;

final class CloudStorageCredentialGuard
{
    public const MASK = '********';

    /**
     * Keep the existing form contract without exposing credentials to the browser.
     */
    public static function redact(array $setting): array
    {
        if (empty($setting)) {
            return $setting;
        }

        $setting['access_key'] = self::MASK;
        $setting['secret_key'] = self::MASK;

        return $setting;
    }

    /**
     * A masked value means "keep the stored credential" for an existing setting.
     */
    public static function resolve(string $submitted, ?string $existing, string $label): string
    {
        $submitted = trim($submitted);

        if ($submitted === self::MASK) {
            if ($existing === null || $existing === '') {
                throw new LogicException("首次配置时必须填写{$label}");
            }

            return $existing;
        }

        if ($submitted === '') {
            throw new LogicException("{$label}不能为空");
        }

        return $submitted;
    }
}
