<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Sorts the files of a pull request for the review effort: the kind of each
 * file, which sets its weight, and whether it is sensitive.
 */
class ReviewEffortFileClassifier
{
    public const KIND_DOCS   = "docs";
    public const KIND_TEST   = "test";
    public const KIND_CONFIG = "config";
    public const KIND_SOURCE = "source";
    public const KIND_INFRA  = "infra";
    public const KIND_SQL    = "sql";


    /**
     * The kind of a file: the first kind one of whose patterns matches its path, source otherwise.
     *
     * @param string $path The file path in the repository.
     *
     * @return string One of the KIND_* constants.
     */
    public function kindOf(string $path): string
    {
        foreach ($this->kindPatterns() as $pattern => $kind) {
            if (preg_match($pattern, $path) === 1) {
                return $kind;
            }
        }

        return self::KIND_SOURCE;

    }//end kindOf()


    /**
     * The weight of a kind of file.
     *
     * @param string $kind One of the KIND_* constants.
     *
     * @return float
     */
    public function weightOf(string $kind): float
    {
        $weights = $this->kindWeights();

        return ($weights[$kind] ?? 1.0);

    }//end weightOf()


    /**
     * Whether the added lines of a kind of file are code, scanned for branching.
     *
     * @param string $kind One of the KIND_* constants.
     *
     * @return boolean
     */
    public function isCode(string $kind): bool
    {
        return in_array($kind, [self::KIND_SOURCE, self::KIND_TEST, self::KIND_INFRA, self::KIND_SQL], true);

    }//end isCode()


    /**
     * Whether a file is sensitive: auth, security, secrets, migrations, CI or deployment.
     *
     * @param string $path The file path in the repository.
     *
     * @return boolean
     */
    public function isRisky(string $path): bool
    {
        foreach ($this->riskPatterns() as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;

    }//end isRisky()


    /**
     * The weight of each kind of file.
     *
     * @return array<string, float>
     */
    private function kindWeights(): array
    {
        $weights = [];
        $weights[self::KIND_DOCS]   = 0.3;
        $weights[self::KIND_TEST]   = 0.5;
        $weights[self::KIND_CONFIG] = 0.6;
        $weights[self::KIND_SOURCE] = 1.0;
        $weights[self::KIND_INFRA]  = 1.3;
        $weights[self::KIND_SQL]    = 1.5;

        return $weights;

    }//end kindWeights()


    /**
     * The path patterns of each kind, in the order they are tried.
     *
     * @return array<string, string> The kind of each pattern.
     */
    private function kindPatterns(): array
    {
        $patterns = [];
        $patterns['/(^|\/)(tests?|__tests__|specs?)\//i']  = self::KIND_TEST;
        $patterns['/[a-z0-9](Test|Tests|Spec)\.[a-zA-Z]+$/'] = self::KIND_TEST;
        $patterns['/\.(test|spec)\.[a-z]+$/i']             = self::KIND_TEST;
        $patterns['/(^|\/)test_[^\/]*\.py$/i']             = self::KIND_TEST;
        $patterns['/_test\.(go|py)$/i']                    = self::KIND_TEST;
        $patterns['/\.sql$/i']                             = self::KIND_SQL;
        $patterns['/(^|\/)migrations?\//i']                = self::KIND_SQL;
        $patterns['/^\.github\/workflows\//i']             = self::KIND_INFRA;
        $patterns['/(^|\/)dockerfile[^\/]*$/i']            = self::KIND_INFRA;
        $patterns['/(^|\/)docker-compose[^\/]*\.ya?ml$/i'] = self::KIND_INFRA;
        $patterns['/\.(tf|tfvars|hcl|sh|bash|ps1)$/i']     = self::KIND_INFRA;
        $patterns['/(^|\/)(\.htaccess|makefile)$/i']       = self::KIND_INFRA;
        $patterns['/\.(md|markdown|rst|txt|adoc)$/i']      = self::KIND_DOCS;
        $patterns['/(^|\/)docs?\//i']                      = self::KIND_DOCS;
        $patterns['/(^|\/)(license|changelog|authors|contributors)$/i'] = self::KIND_DOCS;
        $patterns['/\.(json|ya?ml|xml|ini|toml|cfg|conf|config|properties|csv|lock|svg)$/i'] = self::KIND_CONFIG;
        $patterns['/(^|\/)\.[^\/]+$/']                     = self::KIND_CONFIG;

        return $patterns;

    }//end kindPatterns()


    /**
     * The patterns of the sensitive paths.
     *
     * @return string[]
     */
    private function riskPatterns(): array
    {
        $patterns   = [];
        $patterns[] = '/(^|\/)(auth|authentication|authorization|security|crypto|oauth|sso|permissions?)([^a-z]|$)/';
        $patterns[] = '/(^|\/)(Auth|Authentication|Authorization|Security|Crypto|OAuth|Sso|SSO|Permissions?)([^a-z]|$)/';
        $patterns[] = '/(token|secret|password|credential|encrypt|decrypt)[^\/]*$/i';
        $patterns[] = '/(^|\/)migrations?\//i';
        $patterns[] = '/\.sql$/i';
        $patterns[] = '/^\.github\/workflows\//i';
        $patterns[] = '/(^|\/)dockerfile[^\/]*$/i';
        $patterns[] = '/(^|\/)docker-compose[^\/]*\.ya?ml$/i';
        $patterns[] = '/(^|\/)\.env[^\/]*$/i';
        $patterns[] = '/(^|\/)\.htaccess$/i';

        return $patterns;

    }//end riskPatterns()


}//end class
