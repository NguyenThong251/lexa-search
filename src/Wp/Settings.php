<?php

namespace Lexa\Wp;

/**
 * Single registered option for plugin settings.
 */
final class Settings
{
    public const OPTION = 'lexa_settings';

    public static function get(): array
    {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'engine'     => 'mysql',
            'enabled'    => true, // hand front-end product search to the engine (once the index is ready)
            'post_types' => ['product', 'post', 'page'],
        ]);
    }

    public static function isEnabled(): bool
    {
        return (bool) self::get()['enabled'];
    }

    /** @return string[] */
    public static function postTypes(): array
    {
        $types = (array) self::get()['post_types'];
        return array_values(array_filter(array_map('sanitize_key', $types)));
    }

    public static function sanitize(array $input): array
    {
        $types = isset($input['post_types']) ? array_map('sanitize_key', (array) $input['post_types']) : ['product'];
        return [
            'engine'     => 'mysql',
            'enabled'    => !empty($input['enabled']),
            'post_types' => array_values(array_filter($types)),
        ];
    }
}
