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

            // Freshness: push recently added/updated products up the results.
            // off | light | medium | strong | date  ('date' = strict newest-first)
            'recency_mode'     => 'medium',
            // created  -> post_date     ("mới đăng")
            // modified -> post_modified (bumped by any edit, including stock/price)
            'recency_basis'    => 'created',
            // Days after which the boost has decayed to half.
            'recency_halflife' => 180,
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

    /** off | light | medium | strong | date */
    public static function recencyMode(): string
    {
        $mode = (string) self::get()['recency_mode'];
        return in_array($mode, \Lexa\Engine\RecencyReranker::MODES, true) ? $mode : 'off';
    }

    /** The wp_posts column the freshness boost reads: 'post_date' or 'post_modified'. */
    public static function recencyColumn(): string
    {
        return self::get()['recency_basis'] === 'modified' ? 'post_modified_gmt' : 'post_date_gmt';
    }

    public static function recencyHalfLifeDays(): int
    {
        return max(1, (int) self::get()['recency_halflife']);
    }

    public static function sanitize(array $input): array
    {
        $types = isset($input['post_types']) ? array_map('sanitize_key', (array) $input['post_types']) : ['product'];

        // Defaults here mirror get()'s, so a partial payload cannot quietly
        // change behaviour just by omitting a field.
        $mode = isset($input['recency_mode']) ? (string) $input['recency_mode'] : 'medium';
        if (!in_array($mode, \Lexa\Engine\RecencyReranker::MODES, true)) {
            $mode = 'medium';
        }

        $basis = (isset($input['recency_basis']) && $input['recency_basis'] === 'modified') ? 'modified' : 'created';

        // An emptied number input still posts the key as '', and (int) '' is 0,
        // which max(1, …) would clamp to a 1-day half-life rather than the
        // default. Treat blank as "unset".
        $rawHalfLife = $input['recency_halflife'] ?? '';
        $halfLife    = (is_string($rawHalfLife) && trim($rawHalfLife) === '') ? 180 : (int) $rawHalfLife;
        if ($halfLife < 1) {
            $halfLife = 180;
        }

        return [
            'engine'     => 'mysql',
            // The form posts this as a checkbox. It must stay in the form: an
            // unchecked/absent box means "disabled", so a form that omits the
            // field entirely would silently switch the engine off on every save.
            'enabled'    => !empty($input['enabled']),
            'post_types' => array_values(array_filter($types)),

            'recency_mode'     => $mode,
            'recency_basis'    => $basis,
            'recency_halflife' => min(3650, $halfLife),
        ];
    }
}
