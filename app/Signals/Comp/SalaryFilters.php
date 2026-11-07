<?php

declare(strict_types=1);

namespace App\Signals\Comp;

use Illuminate\Http\Request;

/**
 * Filters for the compensation explorer, read from the query string.
 * Unknown values fall back to the defaults.
 *
 *  - discipline: `tech` (any engineering discipline), `all` (every listing,
 *    including ones with no discipline) or one discipline key;
 *  - level: `any` or one key from SalaryInsights::LEVELS;
 *  - region: `any` or one location bucket;
 *  - window: days back (90, 180) or 0 for all time.
 */
final readonly class SalaryFilters
{
    public const string DISCIPLINE_TECH = 'tech';

    public const string DISCIPLINE_ALL = 'all';

    public const string ANY = 'any';

    public const int DEFAULT_WINDOW = 90;

    /** @var list<int> */
    public const array WINDOWS = [90, 180, 0];

    public function __construct(
        public string $discipline = self::DISCIPLINE_TECH,
        public string $level = self::ANY,
        public string $region = self::ANY,
        public int $window = self::DEFAULT_WINDOW,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $discipline = (string) $request->query('discipline', self::DISCIPLINE_TECH);
        if (! in_array($discipline, [self::DISCIPLINE_TECH, self::DISCIPLINE_ALL], true)
            && ! array_key_exists($discipline, SalaryInsights::DISCIPLINES)) {
            $discipline = self::DISCIPLINE_TECH;
        }

        $level = (string) $request->query('level', self::ANY);
        if (! array_key_exists($level, SalaryInsights::LEVELS)) {
            $level = self::ANY;
        }

        $region = (string) $request->query('region', self::ANY);
        if (! array_key_exists($region, SalaryInsights::REGIONS)) {
            $region = self::ANY;
        }

        $window = $request->query('window');
        $window = is_numeric($window) && in_array((int) $window, self::WINDOWS, true)
            ? (int) $window
            : self::DEFAULT_WINDOW;

        return new self($discipline, $level, $region, $window);
    }

    /**
     * @return array{discipline: string, level: string, region: string, window: int}
     */
    public function toArray(): array
    {
        return [
            'discipline' => $this->discipline,
            'level' => $this->level,
            'region' => $this->region,
            'window' => $this->window,
        ];
    }
}
