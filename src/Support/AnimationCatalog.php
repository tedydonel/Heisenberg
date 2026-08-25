<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * The block animation catalog — every preset the `supports.animation`
 * capability offers, grouped for the inspector, compiled to one shared
 * stylesheet the editor AND the public page load.
 *
 * Contract with the runtime:
 *   - a block with `attributes.animate = <key>` renders with class
 *     `hb-anim-<key>` and attribute `data-hb-anim="<key>"`;
 *   - `.hb-anim-wait` (added by JS only — no-JS pages never hide content)
 *     holds an entrance preset invisible until it enters the viewport;
 *   - `.hb-anim-play` runs the keyframes; duration/delay ride in as the
 *     unitless integers `--hb-anim-dur` / `--hb-anim-delay` (× 1ms here);
 *   - easing arrives as a `hb-ease-<key>` class (enum-validated), which
 *     pins `--hb-anim-ease`;
 *   - `prefers-reduced-motion` disables the whole system.
 */
final class AnimationCatalog
{
    public const DEFAULT_DURATION = 600;

    public const DEFAULT_DELAY = 0;

    public const DEFAULT_EASING = 'smooth';

    /** Easing key -> CSS timing function (enum-validated, class-delivered). */
    public const EASINGS = [
        'smooth' => 'cubic-bezier(0.22, 1, 0.36, 1)',
        'ease' => 'ease',
        'ease-in' => 'ease-in',
        'ease-out' => 'ease-out',
        'ease-in-out' => 'ease-in-out',
        'spring' => 'cubic-bezier(0.34, 1.56, 0.64, 1)',
        'bounce' => 'cubic-bezier(0.68, -0.55, 0.265, 1.55)',
        'linear' => 'linear',
    ];

    /**
     * Preset key -> [group label, human label, entrance?, keyframes body].
     * Entrance presets hide while `.hb-anim-wait`; attention presets loop
     * in place while `.hb-anim-play` sticks.
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    private const PRESETS = [
                'fade' => ['Fade', 'Fade in', true, 'from { opacity: 0; } to { opacity: 1; }'],
        'fade-up' => ['Fade', 'Fade up', true, 'from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: none; }'],
        'fade-down' => ['Fade', 'Fade down', true, 'from { opacity: 0; transform: translateY(-28px); } to { opacity: 1; transform: none; }'],
        'fade-left' => ['Fade', 'Fade left', true, 'from { opacity: 0; transform: translateX(32px); } to { opacity: 1; transform: none; }'],
        'fade-right' => ['Fade', 'Fade right', true, 'from { opacity: 0; transform: translateX(-32px); } to { opacity: 1; transform: none; }'],

                'slide-up' => ['Slide', 'Slide up', true, 'from { opacity: 0; transform: translateY(64px); } to { opacity: 1; transform: none; }'],
        'slide-down' => ['Slide', 'Slide down', true, 'from { opacity: 0; transform: translateY(-64px); } to { opacity: 1; transform: none; }'],
        'slide-left' => ['Slide', 'Slide left', true, 'from { opacity: 0; transform: translateX(72px); } to { opacity: 1; transform: none; }'],
        'slide-right' => ['Slide', 'Slide right', true, 'from { opacity: 0; transform: translateX(-72px); } to { opacity: 1; transform: none; }'],

                'zoom' => ['Zoom', 'Zoom in', true, 'from { opacity: 0; transform: scale(0.82); } to { opacity: 1; transform: none; }'],
        'zoom-out' => ['Zoom', 'Zoom out', true, 'from { opacity: 0; transform: scale(1.16); } to { opacity: 1; transform: none; }'],
        'zoom-up' => ['Zoom', 'Zoom in up', true, 'from { opacity: 0; transform: scale(0.86) translateY(36px); } to { opacity: 1; transform: none; }'],
        'zoom-down' => ['Zoom', 'Zoom in down', true, 'from { opacity: 0; transform: scale(0.86) translateY(-36px); } to { opacity: 1; transform: none; }'],

                'rotate-in' => ['Flip & Rotate', 'Rotate in', true, 'from { opacity: 0; transform: rotate(-8deg) scale(0.92); } to { opacity: 1; transform: none; }'],
        'rotate-in-left' => ['Flip & Rotate', 'Rotate in left', true, 'from { opacity: 0; transform: rotate(6deg) translateX(-40px); } to { opacity: 1; transform: none; }'],
        'swing-in' => ['Flip & Rotate', 'Swing in', true, '0% { opacity: 0; transform: rotate(-6deg); } 60% { opacity: 1; transform: rotate(3deg); } 80% { transform: rotate(-1.5deg); } 100% { opacity: 1; transform: none; }'],
        'skew-in' => ['Flip & Rotate', 'Skew in', true, 'from { opacity: 0; transform: skewX(-12deg) translateX(-48px); } to { opacity: 1; transform: none; }'],

                'flip-3d-x' => ['3D', 'Flip 3D — X axis', true, 'from { opacity: 0; transform: perspective(900px) rotateX(-72deg); } to { opacity: 1; transform: perspective(900px) rotateX(0); }'],
        'flip-3d-y' => ['3D', 'Flip 3D — Y axis', true, 'from { opacity: 0; transform: perspective(900px) rotateY(64deg); } to { opacity: 1; transform: perspective(900px) rotateY(0); }'],
        'door-3d' => ['3D', 'Door swing 3D', true, 'from { opacity: 0; transform: perspective(1000px) rotateY(-88deg); transform-origin: left center; } to { opacity: 1; transform: perspective(1000px) rotateY(0); transform-origin: left center; }'],
        'tilt-3d' => ['3D', 'Tilt in 3D', true, 'from { opacity: 0; transform: perspective(800px) rotateX(24deg) translateY(48px) scale(0.94); } to { opacity: 1; transform: perspective(800px) none; }'],
        'rise-3d' => ['3D', 'Rise 3D', true, 'from { opacity: 0; transform: perspective(900px) translateZ(-160px) translateY(40px); } to { opacity: 1; transform: perspective(900px) translateZ(0) translateY(0); }'],
        'unfold-3d' => ['3D', 'Unfold 3D', true, '0% { opacity: 0; transform: perspective(1000px) rotateX(-84deg); transform-origin: top center; } 60% { opacity: 1; transform: perspective(1000px) rotateX(6deg); transform-origin: top center; } 100% { opacity: 1; transform: perspective(1000px) rotateX(0); transform-origin: top center; }'],
        'orbit-3d' => ['3D', 'Orbit in 3D', true, 'from { opacity: 0; transform: perspective(900px) rotateY(96deg) translateX(64px) scale(0.9); } to { opacity: 1; transform: perspective(900px) none; }'],

                'blur-in' => ['Reveal', 'Blur in', true, 'from { opacity: 0; filter: blur(14px); } to { opacity: 1; filter: blur(0); }'],
        'reveal-up' => ['Reveal', 'Reveal up', true, 'from { opacity: 0; clip-path: inset(100% 0 0 0); transform: translateY(14px); } to { opacity: 1; clip-path: inset(0 0 0 0); transform: none; }'],
        'reveal-left' => ['Reveal', 'Reveal left', true, 'from { opacity: 0; clip-path: inset(0 0 0 100%); } to { opacity: 1; clip-path: inset(0 0 0 0); }'],
        'wipe-in' => ['Reveal', 'Wipe in', true, 'from { opacity: 0; clip-path: inset(0 100% 0 0); } to { opacity: 1; clip-path: inset(0 0 0 0); }'],
        'letter-space' => ['Reveal', 'Letter spread', true, 'from { opacity: 0; letter-spacing: 0.5em; } to { opacity: 1; letter-spacing: normal; }'],

                'bounce-in' => ['Bounce', 'Bounce in', true, '0% { opacity: 0; transform: scale(0.3); } 50% { opacity: 1; transform: scale(1.05); } 70% { transform: scale(0.96); } 100% { opacity: 1; transform: none; }'],
        'bounce-up' => ['Bounce', 'Bounce up', true, '0% { opacity: 0; transform: translateY(72px); } 55% { opacity: 1; transform: translateY(-10px); } 75% { transform: translateY(4px); } 100% { opacity: 1; transform: none; }'],
        'elastic-in' => ['Bounce', 'Elastic in', true, '0% { opacity: 0; transform: scaleX(0.6); } 55% { opacity: 1; transform: scaleX(1.06); } 75% { transform: scaleX(0.97); } 100% { opacity: 1; transform: none; }'],
        'drop-in' => ['Bounce', 'Drop in', true, '0% { opacity: 0; transform: translateY(-90px) scaleY(1.08); } 60% { opacity: 1; transform: translateY(6px) scaleY(0.98); } 80% { transform: translateY(-2px); } 100% { opacity: 1; transform: none; }'],

                'pulse' => ['Attention', 'Pulse', false, '0% { transform: scale(1); } 50% { transform: scale(1.04); } 100% { transform: scale(1); }'],
        'float' => ['Attention', 'Float', false, '0% { transform: translateY(0); } 50% { transform: translateY(-8px); } 100% { transform: translateY(0); }'],
        'wobble' => ['Attention', 'Wobble', false, '0% { transform: rotate(0); } 25% { transform: rotate(1.6deg); } 75% { transform: rotate(-1.6deg); } 100% { transform: rotate(0); }'],
        'shake' => ['Attention', 'Shake', false, '0%, 100% { transform: translateX(0); } 20% { transform: translateX(-5px); } 40% { transform: translateX(5px); } 60% { transform: translateX(-3px); } 80% { transform: translateX(3px); }'],
        'heartbeat' => ['Attention', 'Heartbeat', false, '0%, 100% { transform: scale(1); } 14% { transform: scale(1.06); } 28% { transform: scale(1); } 42% { transform: scale(1.06); } 70% { transform: scale(1); }'],
        'glow' => ['Attention', 'Glow', false, '0%, 100% { filter: brightness(1); } 50% { filter: brightness(1.25); }'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    /** @return list<string> */
    public static function easingKeys(): array
    {
        return array_keys(self::EASINGS);
    }

    public static function has(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    public static function isEntrance(string $key): bool
    {
        return self::PRESETS[$key][2] ?? true;
    }

    /**
     * Inspector options: `[['value' => '', 'label' => 'None'], …]`, each
     * preset carrying its group so the select can render optgroups.
     *
     * @return list<array{value: string, label: string, group?: string}>
     */
    public static function options(): array
    {
        $options = [['value' => '', 'label' => 'None']];
        foreach (self::PRESETS as $key => [$group, $label]) {
            $options[] = ['value' => $key, 'label' => $label, 'group' => $group];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function easingOptions(): array
    {
        $labels = [
            'smooth' => 'Smooth', 'ease' => 'Ease', 'ease-in' => 'Ease in',
            'ease-out' => 'Ease out', 'ease-in-out' => 'Ease in-out',
            'spring' => 'Spring', 'bounce' => 'Bounce', 'linear' => 'Linear',
        ];
        $options = [];
        foreach (self::EASINGS as $key => $fn) {
            $options[] = ['value' => $key, 'label' => $labels[$key] ?? ucfirst($key)];
        }

        return $options;
    }

    /** The shared stylesheet: keyframes + trigger classes + easing + a11y. */
    public static function css(): string
    {
        $css = ["/* Heisenberg animation catalog — generated from AnimationCatalog. */"];

        foreach (self::EASINGS as $key => $fn) {
            $css[] = ".hb-ease-{$key} { --hb-anim-ease: {$fn}; }";
        }

        foreach (self::PRESETS as $key => [$group, $label, $entrance, $frames]) {
            $css[] = "@keyframes hb-{$key} { {$frames} }";
            $loop = $entrance ? '' : ' infinite';
            $css[] = ".hb-anim-{$key}.hb-anim-play { animation: hb-{$key}"
                . ' calc(var(--hb-anim-dur, ' . self::DEFAULT_DURATION . ') * 1ms)'
                . ' var(--hb-anim-ease, ' . self::EASINGS[self::DEFAULT_EASING] . ')'
                . ' calc(var(--hb-anim-delay, ' . self::DEFAULT_DELAY . ') * 1ms)'
                . " both{$loop}; }";
            if ($entrance) {
                $css[] = ".hb-anim-{$key}.hb-anim-wait { opacity: 0; }";
            }
        }

        $css[] = '@media (prefers-reduced-motion: reduce) {';
        $css[] = '  [data-hb-anim] { animation: none !important; opacity: 1 !important; transform: none !important; filter: none !important; clip-path: none !important; }';
        $css[] = '}';

        return implode("\n", $css);
    }
}
