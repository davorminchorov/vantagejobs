<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canonical stack-tag dictionary
|--------------------------------------------------------------------------
|
| Hand-edited reference of ~200 technologies. Used by StackTagsExtractor
| to normalize raw source-tagged tokens (Remotive.tags, RemoteOK.tags) and
| body-text mentions into a canonical set.
|
| Entry shape:
|   <canonical> => [
|       'aliases' => ['alt1', 'alt2', ...],        // optional, matched as additional patterns
|       'requires' => '/regex/i',                  // optional, REPLACES the default
|                                                  //   /\bcanonical\b|\balias1\b/i pattern
|       'context'  => ['cue1', 'cue2', ...],       // optional, at least one must appear in
|                                                  //   the same body for a body match to count
|                                                  //   (structured-source matches bypass this)
|       'category' => 'language' | 'frontend' | 'backend' | 'mobile'
|                    | 'database' | 'cloud' | 'infra' | 'data' | 'ml'
|                    | 'devops' | 'test' | 'protocol',
|   ]
|
| Rule of thumb for `context`: required for any tag that is a common English
| word, single letter, or short ambiguous token (`go`, `r`, `c`, `d`, `ruby`
| is fine, `rust` is fine). Without context, body-text matches are dropped.
|
*/

return [
    /* ------------------------------------------------------------------
     | Languages
     * ------------------------------------------------------------------ */
    'python' => ['aliases' => ['py'], 'category' => 'language'],
    'javascript' => ['aliases' => ['js'], 'category' => 'language'],
    'typescript' => ['aliases' => ['ts'], 'category' => 'language'],
    'java' => ['category' => 'language'],
    'kotlin' => ['category' => 'language'],
    'scala' => ['category' => 'language'],
    'groovy' => ['category' => 'language'],
    'ruby' => ['category' => 'language'],
    'php' => ['category' => 'language'],
    'swift' => ['category' => 'language'],
    'objective-c' => ['aliases' => ['objc', 'objective c'], 'category' => 'language'],
    'rust' => ['category' => 'language'],
    'elixir' => ['category' => 'language'],
    'erlang' => ['category' => 'language'],
    'clojure' => ['aliases' => ['clojurescript'], 'category' => 'language'],
    'haskell' => ['category' => 'language'],
    'ocaml' => ['category' => 'language'],
    'reason' => ['aliases' => ['reasonml'], 'category' => 'language'],
    'dart' => ['category' => 'language'],
    'julia' => ['category' => 'language'],
    'perl' => ['category' => 'language'],
    'lua' => ['category' => 'language'],
    'zig' => ['category' => 'language'],
    'nim' => ['category' => 'language'],
    'crystal' => ['category' => 'language'],
    'solidity' => ['category' => 'language'],

    // Ambiguous / short tokens — context required for body matches.
    'go' => [
        'aliases' => ['golang'],
        'requires' => '/\b(?:golang|go)\b/i',
        'context' => [
            'golang', 'goroutine', 'gorilla', 'gopher', 'gin', 'echo framework',
            'fiber', 'grpc', 'protobuf', 'kubernetes', 'docker', 'microservices',
            'cobra', 'viper', 'gorm',
        ],
        'category' => 'language',
    ],
    'r' => [
        'requires' => '/\br\b/i',
        'context' => [
            'rstudio', 'cran', 'tidyverse', 'ggplot', 'dplyr', 'shiny',
            'data science', 'statistics', 'biostatistics', 'bioinformatics',
        ],
        'category' => 'language',
    ],
    'c' => [
        'requires' => '/\bc\b/i',
        'context' => [
            'gcc', 'clang', 'libc', 'posix', 'embedded', 'firmware',
            'kernel', 'systems programming', 'driver', 'cmake', 'autotools',
        ],
        'category' => 'language',
    ],
    'c++' => [
        'aliases' => ['cpp', 'cplusplus'],
        'requires' => '/(?<![\w+])(?:c\+\+|cplusplus|cpp)(?![\w+])/i',
        'category' => 'language',
    ],
    'c#' => [
        'aliases' => ['csharp', 'c sharp'],
        'requires' => '/(?<![\w])(?:c#|csharp|c\s+sharp)(?![\w])/i',
        'category' => 'language',
    ],
    'f#' => [
        'aliases' => ['fsharp', 'f sharp'],
        'requires' => '/(?<![\w])(?:f#|fsharp|f\s+sharp)(?![\w])/i',
        'category' => 'language',
    ],
    'd' => [
        'requires' => '/\bd\b/i',
        'context' => ['dlang', 'd language', 'phobos', 'tango', 'dub package'],
        'category' => 'language',
    ],
    'sql' => ['aliases' => ['plsql', 'tsql'], 'category' => 'language'],
    'bash' => ['aliases' => ['shell scripting', 'shellscript'], 'category' => 'language'],
    'powershell' => ['aliases' => ['pwsh'], 'category' => 'language'],

    /* ------------------------------------------------------------------
     | Frontend
     * ------------------------------------------------------------------ */
    'react' => ['aliases' => ['reactjs', 'react.js'], 'category' => 'frontend'],
    'vue' => ['aliases' => ['vuejs', 'vue.js'], 'category' => 'frontend'],
    'angular' => ['aliases' => ['angularjs', 'angular.js'], 'category' => 'frontend'],
    'svelte' => ['aliases' => ['sveltekit'], 'category' => 'frontend'],
    'solid' => [
        'aliases' => ['solidjs'],
        'requires' => '/\bsolidjs\b/i',
        'category' => 'frontend',
    ],
    'ember' => ['aliases' => ['emberjs', 'ember.js'], 'category' => 'frontend'],
    'backbone' => ['aliases' => ['backbonejs'], 'category' => 'frontend'],
    'jquery' => ['category' => 'frontend'],
    'nextjs' => ['aliases' => ['next.js', 'next js'], 'category' => 'frontend'],
    'nuxt' => ['aliases' => ['nuxtjs', 'nuxt.js'], 'category' => 'frontend'],
    'gatsby' => ['aliases' => ['gatsbyjs'], 'category' => 'frontend'],
    'remix' => [
        'aliases' => ['remix.run'],
        'requires' => '/\bremix(?:\.run)?\b/i',
        'context' => ['remix.run', 'remix framework', 'react', 'loader', 'action', 'meta', 'remix-run'],
        'category' => 'frontend',
    ],
    'astro' => [
        'requires' => '/\bastro\b/i',
        'context' => ['astro', 'astro framework', 'islands', 'mdx', 'static site'],
        'category' => 'frontend',
    ],
    'inertia' => ['aliases' => ['inertiajs', 'inertia.js'], 'category' => 'frontend'],
    'livewire' => ['category' => 'frontend'],
    'htmx' => ['category' => 'frontend'],
    'alpine' => [
        'aliases' => ['alpinejs', 'alpine.js'],
        'requires' => '/\balpinejs\b|\balpine\.js\b/i',
        'category' => 'frontend',
    ],
    'tailwind' => ['aliases' => ['tailwindcss'], 'category' => 'frontend'],
    'bootstrap' => ['category' => 'frontend'],
    'mui' => ['aliases' => ['material-ui', 'material ui'], 'category' => 'frontend'],
    'chakra' => ['aliases' => ['chakra-ui', 'chakra ui'], 'category' => 'frontend'],
    'shadcn' => ['aliases' => ['shadcn/ui', 'shadcn ui'], 'category' => 'frontend'],
    'redux' => ['category' => 'frontend'],
    'mobx' => ['category' => 'frontend'],
    'zustand' => ['category' => 'frontend'],
    'recoil' => [
        'aliases' => ['recoiljs'],
        'requires' => '/\brecoiljs\b/i',
        'category' => 'frontend',
    ],
    'jotai' => ['category' => 'frontend'],
    'rxjs' => ['category' => 'frontend'],
    'webpack' => ['category' => 'frontend'],
    'vite' => ['category' => 'frontend'],
    'rollup' => ['category' => 'frontend'],
    'esbuild' => ['category' => 'frontend'],
    'turbopack' => ['category' => 'frontend'],

    /* ------------------------------------------------------------------
     | Backend frameworks
     * ------------------------------------------------------------------ */
    'django' => ['category' => 'backend'],
    'flask' => ['category' => 'backend'],
    'fastapi' => ['category' => 'backend'],
    'rails' => ['aliases' => ['ruby on rails', 'ror'], 'category' => 'backend'],
    'sinatra' => ['category' => 'backend'],
    'express' => ['aliases' => ['expressjs', 'express.js'], 'category' => 'backend'],
    'nestjs' => ['aliases' => ['nest.js'], 'category' => 'backend'],
    'fastify' => ['category' => 'backend'],
    'koa' => ['category' => 'backend'],
    'hapi' => ['aliases' => ['hapijs'], 'category' => 'backend'],
    'spring' => ['aliases' => ['spring boot', 'springboot'], 'category' => 'backend'],
    'laravel' => ['category' => 'backend'],
    'symfony' => ['category' => 'backend'],
    'cakephp' => ['category' => 'backend'],
    'codeigniter' => ['category' => 'backend'],
    'asp.net' => ['aliases' => ['aspnet', 'asp net'], 'category' => 'backend'],
    'dotnet' => [
        'aliases' => ['.net', 'dot net'],
        // `.net` starts with a non-word char so `\b.net\b` would fail to match.
        // Hand-rolled lookarounds enforce the same "surrounded by non-[\w]" rule.
        'requires' => '/(?<![\w])(?:dotnet|\.net|dot\s+net)(?![\w])/i',
        'category' => 'backend',
    ],
    'phoenix' => [
        'aliases' => ['phoenix framework'],
        'requires' => '/\bphoenix(?:\s+framework)?\b/i',
        'context' => ['elixir', 'erlang', 'liveview', 'ecto', 'phoenix framework'],
        'category' => 'backend',
    ],
    'gin' => [
        'requires' => '/\bgin\b/i',
        'context' => ['golang', 'go ', 'gin framework', 'gin-gonic'],
        'category' => 'backend',
    ],
    'actix' => ['aliases' => ['actix-web'], 'category' => 'backend'],
    'rocket' => [
        'requires' => '/\brocket\b/i',
        'context' => ['rust', 'rocket framework', 'rocket.rs'],
        'category' => 'backend',
    ],
    'axum' => [
        'requires' => '/\baxum\b/i',
        'context' => ['rust', 'tokio'],
        'category' => 'backend',
    ],

    /* ------------------------------------------------------------------
     | Mobile
     * ------------------------------------------------------------------ */
    'react-native' => ['aliases' => ['reactnative', 'react native'], 'category' => 'mobile'],
    'flutter' => ['category' => 'mobile'],
    'ionic' => ['category' => 'mobile'],
    'xamarin' => ['category' => 'mobile'],
    'cordova' => ['category' => 'mobile'],
    'capacitor' => ['category' => 'mobile'],
    'expo' => [
        'requires' => '/\bexpo\b/i',
        'context' => ['react native', 'reactnative', 'expo go', 'expo sdk'],
        'category' => 'mobile',
    ],
    'swiftui' => ['category' => 'mobile'],
    'jetpack-compose' => ['aliases' => ['jetpack compose'], 'category' => 'mobile'],
    'kotlin-multiplatform' => ['aliases' => ['kmp', 'kotlin multiplatform'], 'category' => 'mobile'],

    /* ------------------------------------------------------------------
     | Databases
     * ------------------------------------------------------------------ */
    'postgres' => ['aliases' => ['postgresql', 'pg', 'psql'], 'category' => 'database'],
    'mysql' => ['category' => 'database'],
    'mariadb' => ['category' => 'database'],
    'sqlite' => ['category' => 'database'],
    'oracle' => [
        'requires' => '/\boracle\b/i',
        'context' => ['oracle db', 'oracle database', 'pl/sql', 'plsql', 'rac', 'tns'],
        'category' => 'database',
    ],
    'sqlserver' => ['aliases' => ['sql server', 'mssql'], 'category' => 'database'],
    'mongodb' => ['aliases' => ['mongo'], 'category' => 'database'],
    'dynamodb' => ['category' => 'database'],
    'cassandra' => ['category' => 'database'],
    'scylladb' => ['aliases' => ['scylla'], 'category' => 'database'],
    'redis' => ['category' => 'database'],
    'memcached' => ['category' => 'database'],
    'elasticsearch' => ['aliases' => ['elastic search', 'es'], 'category' => 'database'],
    'opensearch' => ['category' => 'database'],
    'neo4j' => ['category' => 'database'],
    'arangodb' => ['category' => 'database'],
    'couchdb' => ['category' => 'database'],
    'rethinkdb' => ['category' => 'database'],
    'influxdb' => ['category' => 'database'],
    'timescaledb' => ['category' => 'database'],
    'clickhouse' => ['category' => 'database'],
    'snowflake' => ['category' => 'database'],
    'bigquery' => ['category' => 'database'],
    'redshift' => ['category' => 'database'],
    'supabase' => ['category' => 'database'],
    'planetscale' => ['category' => 'database'],
    'cockroachdb' => ['aliases' => ['cockroach'], 'category' => 'database'],
    'duckdb' => ['category' => 'database'],

    /* ------------------------------------------------------------------
     | Cloud + infra
     * ------------------------------------------------------------------ */
    'aws' => ['aliases' => ['amazon web services'], 'category' => 'cloud'],
    'gcp' => ['aliases' => ['google cloud', 'google cloud platform'], 'category' => 'cloud'],
    'azure' => ['category' => 'cloud'],
    'cloudflare' => ['category' => 'cloud'],
    'fastly' => ['category' => 'cloud'],
    'vercel' => ['category' => 'cloud'],
    'netlify' => ['category' => 'cloud'],
    'heroku' => ['category' => 'cloud'],
    'render' => [
        'requires' => '/\brender\.com\b/i',
        'category' => 'cloud',
    ],
    'fly' => ['aliases' => ['fly.io'], 'category' => 'cloud'],
    'digitalocean' => ['aliases' => ['digital ocean'], 'category' => 'cloud'],
    'hetzner' => ['category' => 'cloud'],
    'linode' => ['category' => 'cloud'],
    'kubernetes' => ['aliases' => ['k8s'], 'category' => 'infra'],
    'docker' => ['category' => 'infra'],
    'podman' => ['category' => 'infra'],
    'terraform' => ['category' => 'infra'],
    'pulumi' => ['category' => 'infra'],
    'ansible' => ['category' => 'infra'],
    'puppet' => ['category' => 'infra'],
    'chef' => [
        'requires' => '/\bchef\b/i',
        'context' => ['chef cookbook', 'chef recipe', 'configuration management', 'devops'],
        'category' => 'infra',
    ],
    'salt' => [
        'requires' => '/\bsaltstack\b/i',
        'category' => 'infra',
    ],
    'helm' => [
        'requires' => '/\bhelm\b/i',
        'context' => ['kubernetes', 'k8s', 'helm chart', 'tiller'],
        'category' => 'infra',
    ],
    'istio' => ['category' => 'infra'],
    'linkerd' => ['category' => 'infra'],
    'consul' => ['category' => 'infra'],
    'vault' => [
        'requires' => '/\bvault\b/i',
        'context' => ['hashicorp', 'secrets management', 'kv store'],
        'category' => 'infra',
    ],
    'nomad' => [
        'requires' => '/\bnomad\b/i',
        'context' => ['hashicorp', 'workload orchestrator'],
        'category' => 'infra',
    ],
    'prometheus' => ['category' => 'infra'],
    'grafana' => ['category' => 'infra'],
    'datadog' => ['category' => 'infra'],
    'newrelic' => ['aliases' => ['new relic'], 'category' => 'infra'],
    'sentry' => ['category' => 'infra'],
    'opentelemetry' => ['aliases' => ['otel'], 'category' => 'infra'],
    'jaeger' => ['category' => 'infra'],
    'nginx' => ['category' => 'infra'],
    'caddy' => [
        'requires' => '/\bcaddy(?:server)?\b/i',
        'category' => 'infra',
    ],
    'haproxy' => ['category' => 'infra'],
    'envoy' => [
        'requires' => '/\benvoy\b/i',
        'context' => ['envoy proxy', 'service mesh', 'istio', 'xds'],
        'category' => 'infra',
    ],

    /* ------------------------------------------------------------------
     | Data + ML
     * ------------------------------------------------------------------ */
    'tensorflow' => ['aliases' => ['tf'], 'category' => 'ml'],
    'pytorch' => ['category' => 'ml'],
    'jax' => [
        'requires' => '/\bjax\b/i',
        'context' => ['google jax', 'autograd', 'xla', 'flax', 'haiku'],
        'category' => 'ml',
    ],
    'keras' => ['category' => 'ml'],
    'scikit-learn' => ['aliases' => ['sklearn'], 'category' => 'ml'],
    'pandas' => ['category' => 'data'],
    'numpy' => ['category' => 'data'],
    'scipy' => ['category' => 'data'],
    'matplotlib' => ['category' => 'data'],
    'jupyter' => ['category' => 'data'],
    'spark' => ['aliases' => ['apache spark', 'pyspark'], 'category' => 'data'],
    'hadoop' => ['category' => 'data'],
    'kafka' => ['aliases' => ['apache kafka'], 'category' => 'data'],
    'pulsar' => ['aliases' => ['apache pulsar'], 'category' => 'data'],
    'flink' => ['aliases' => ['apache flink'], 'category' => 'data'],
    'airflow' => ['aliases' => ['apache airflow'], 'category' => 'data'],
    'dbt' => [
        'requires' => '/\bdbt\b/i',
        'context' => ['dbt cloud', 'dbt core', 'snowflake', 'bigquery', 'analytics engineering'],
        'category' => 'data',
    ],
    'dagster' => ['category' => 'data'],
    'prefect' => ['category' => 'data'],
    'ray' => [
        'requires' => '/\bray\b/i',
        'context' => ['ray serve', 'ray tune', 'ray cluster', 'distributed', 'ml'],
        'category' => 'ml',
    ],
    'mlflow' => ['category' => 'ml'],
    'huggingface' => ['aliases' => ['hugging face', 'hf'], 'category' => 'ml'],
    'langchain' => ['category' => 'ml'],
    'llamaindex' => ['aliases' => ['llama index'], 'category' => 'ml'],
    'openai' => ['category' => 'ml'],
    'anthropic' => ['category' => 'ml'],
    'opencv' => ['category' => 'ml'],
    'nltk' => ['category' => 'ml'],
    'spacy' => ['category' => 'ml'],
    'polars' => ['category' => 'data'],
    'dask' => ['category' => 'data'],

    /* ------------------------------------------------------------------
     | DevOps / CI
     * ------------------------------------------------------------------ */
    'github-actions' => ['aliases' => ['github actions', 'gha'], 'category' => 'devops'],
    'gitlab-ci' => ['aliases' => ['gitlab ci'], 'category' => 'devops'],
    'jenkins' => ['category' => 'devops'],
    'circleci' => ['aliases' => ['circle ci'], 'category' => 'devops'],
    'travis' => ['aliases' => ['travis ci'], 'category' => 'devops'],
    'buildkite' => ['category' => 'devops'],
    'drone' => [
        'requires' => '/\bdrone\s*ci\b/i',
        'category' => 'devops',
    ],
    'argo' => ['aliases' => ['argocd'], 'category' => 'devops'],
    'flux' => [
        'aliases' => ['fluxcd'],
        'requires' => '/\bfluxcd\b/i',
        'category' => 'devops',
    ],
    'spinnaker' => ['category' => 'devops'],

    /* ------------------------------------------------------------------
     | Test
     * ------------------------------------------------------------------ */
    'jest' => ['category' => 'test'],
    'mocha' => ['category' => 'test'],
    'pytest' => ['category' => 'test'],
    'rspec' => ['category' => 'test'],
    'junit' => ['category' => 'test'],
    'cypress' => ['category' => 'test'],
    'playwright' => ['category' => 'test'],
    'selenium' => ['category' => 'test'],
    'vitest' => ['category' => 'test'],
    'testing-library' => ['aliases' => ['testing library'], 'category' => 'test'],
    'phpunit' => ['category' => 'test'],
    'pest' => [
        'requires' => '/\bpestphp\b/i',
        'category' => 'test',
    ],

    /* ------------------------------------------------------------------
     | Protocols + misc
     * ------------------------------------------------------------------ */
    'graphql' => ['category' => 'protocol'],
    'grpc' => ['category' => 'protocol'],
    'rest' => [
        'aliases' => ['restful'],
        'requires' => '/\brestful\b|\brest api\b/i',
        'category' => 'protocol',
    ],
    'websocket' => ['aliases' => ['websockets'], 'category' => 'protocol'],
    'webrtc' => ['category' => 'protocol'],
    'protobuf' => ['aliases' => ['protocol buffers'], 'category' => 'protocol'],
    'oauth' => ['aliases' => ['oauth2'], 'category' => 'protocol'],
    'jwt' => ['category' => 'protocol'],
    'saml' => ['category' => 'protocol'],
    'oidc' => ['category' => 'protocol'],
    'ldap' => ['category' => 'protocol'],
];
