<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\HackerNews;

use App\Scrapers\Aggregator\HackerNews\HackerNewsContextBuilder;
use App\Scrapers\Aggregator\HackerNews\JobSeekerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class JobSeekerDetectorTest extends TestCase
{
    #[Test]
    #[DataProvider('seekerComments')]
    public function flags_job_seekers_posting_in_the_hiring_thread(string $html): void
    {
        $context = (new HackerNewsContextBuilder())->build(['id' => 1, 'text' => $html]);

        $this->assertSame(JobSeekerDetector::REASON, $context->autoDismissReason);
    }

    #[Test]
    #[DataProvider('jobPostings')]
    public function leaves_real_job_postings_alone(string $html): void
    {
        $context = (new HackerNewsContextBuilder())->build(['id' => 1, 'text' => $html]);

        $this->assertNull($context->autoDismissReason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function seekerComments(): array
    {
        return [
            'who-wants-to-be-hired template' => ['Location: Dorset, UK<p>Remote: Yes<p>Willing to relocate: No<p>Technologies: Ruby on Rails, Python<p>Résumé: https:&#x2F;&#x2F;example.com&#x2F;cv.pdf<p>Email: jane@example.com'],
            'template on one line, upper case' => ['LOCATION: Poland (EU) REMOTE: Yes WILLING TO RELOCATE: No TECHNOLOGIES: PHP 8, Symfony'],
            'self introduction' => ['I&#x27;m Jane Doe, a Software Engineer focused on full-stack development and AI-powered applications. I work with React, TypeScript and Python.'],
            'greeting then introduction' => ['Hi! I am Max. I am a Design Leader with 13+ years of experience across design, development and analytics.'],
            'my name is' => ['My name is Priya Shah. Backend developer with 6 years of Go and Kubernetes experience, open to remote roles worldwide.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jobPostings(): array
    {
        return [
            'standard header' => ['Acme | Senior Engineer | Remote (US) | $180k-$220k<p>We build warehouse robots and need help with our Go services.'],
            'founder intro that is hiring' => ['I&#x27;m Jane Doe, founder of Acme Robotics. We&#x27;re hiring a senior backend engineer to help scale our fleet software.'],
            'intro without a name' => ['I&#x27;m the CTO at Initech and we need two platform engineers for our payments team, remote within the EU.'],
            'location and remote fields without relocation' => ['Globex | Data Engineer<p>Location: Berlin<p>Remote: Hybrid<p>Technologies: Python, Spark, dbt<p>Apply at https:&#x2F;&#x2F;globex.example&#x2F;jobs'],
            'relocation offered by the employer' => ['Hooli | SRE | Onsite in Zurich<p>We offer relocation support. Willing to relocate: we cover visa and moving costs for the right candidate.'],
        ];
    }
}
