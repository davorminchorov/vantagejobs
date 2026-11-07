<?php

declare(strict_types=1);

namespace App\Signals\Funding;

/**
 * Free RSS feeds of startup funding news read by signals:funding:feed.
 * The region is the feed's editorial focus, used as a filter on the
 * Recently funded page.
 */
enum FundingFeedSource: string
{
    case TechCrunch = 'techcrunch';
    case Uktn = 'uktn';
    case TechEu = 'tech_eu';
    case CrunchbaseNews = 'crunchbase_news';
    case TechFundingNews = 'tech_funding_news';

    public function url(): string
    {
        return match ($this) {
            self::TechCrunch => 'https://techcrunch.com/category/fundraising/feed/',
            self::Uktn => 'https://www.uktech.news/funding/feed',
            self::TechEu => 'https://tech.eu/feed/',
            self::CrunchbaseNews => 'https://news.crunchbase.com/feed/',
            self::TechFundingNews => 'https://techfundingnews.com/feed/',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TechCrunch => 'TechCrunch',
            self::Uktn => 'UKTN',
            self::TechEu => 'tech.eu',
            self::CrunchbaseNews => 'Crunchbase News',
            self::TechFundingNews => 'Tech Funding News',
        };
    }

    /**
     * @return 'us'|'uk'|'europe'|'global'
     */
    public function region(): string
    {
        return match ($this) {
            self::TechCrunch => 'us',
            self::Uktn => 'uk',
            self::TechEu => 'europe',
            self::CrunchbaseNews, self::TechFundingNews => 'global',
        };
    }
}
