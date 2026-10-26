<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!class_exists('DomainController')) {
    class DomainController
    {
        private string $currentDomain;
        /**
         * @var array<int, string>
         */
        private array $legacyDomains = [];
        private string $devDomain;

        /**
         * DomainController constructor.
         *
         * @param array<string, mixed> $options
         */
        public function __construct(array $options = [])
        {
            $current = $options['current_domain'] ?? getenv('PRODUCT_CURRENT_DOMAIN') ?: site_domain();
            $this->currentDomain = $this->normalizeDomain((string)$current);

            $legacy = $options['legacy_domains'] ?? $this->loadLegacyDomainsFromEnv();
            if (empty($legacy)) {
                $legacy = ['product.example'];
            }

            if (!empty($options['previous_domain'])) {
                $legacy[] = (string)$options['previous_domain'];
            }

            foreach ($legacy as $domain) {
                $normalized = $this->normalizeDomain((string)$domain);
                if ($normalized !== '') {
                    $this->legacyDomains[] = $normalized;
                }
            }

            $this->legacyDomains = array_values(array_unique($this->legacyDomains));

            $dev = $options['dev_domain'] ?? getenv('PRODUCT_DEV_DOMAIN') ?: 'legacy.example';
            $this->devDomain = $this->normalizeDomain((string)$dev);
        }

        /**
         * @return array<int, string>
         */
        private function loadLegacyDomainsFromEnv(): array
        {
            $env = getenv('PRODUCT_LEGACY_DOMAINS');
            if ($env === false || $env === null || $env === '') {
                return [];
            }

            $domains = [];
            foreach (explode(',', $env) as $part) {
                $normalized = $this->normalizeDomain($part);
                if ($normalized !== '') {
                    $domains[] = $normalized;
                }
            }

            return array_values(array_unique($domains));
        }

        private function normalizeDomain(string $domain): string
        {
            $domain = trim($domain);
            if ($domain === '') {
                return '';
            }

            $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
            $domain = preg_replace('#^//#', '', $domain) ?? $domain;
            $domain = strtolower($domain);

            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }

            return rtrim($domain, '/');
        }

        public function currentDomain(): string
        {
            return $this->currentDomain;
        }

        public function devDomain(): string
        {
            return $this->devDomain;
        }

        /**
         * @return array<int, string>
         */
        public function legacyDomains(): array
        {
            return $this->legacyDomains;
        }

        public function hasLegacyDomains(): bool
        {
            return !empty($this->legacyDomains);
        }

        public function currentDomainAlternation(bool $includeWww = true): string
        {
            return $this->buildAlternation([$this->currentDomain], $includeWww);
        }

        public function legacyDomainAlternation(bool $includeWww = true): string
        {
            return $this->buildAlternation($this->legacyDomains, $includeWww);
        }

        public function currentDomainPattern(string $suffixPattern = '', bool $protocolOptional = true, bool $includeWww = true): string
        {
            return $this->buildDomainPattern([$this->currentDomain], $suffixPattern, $protocolOptional, $includeWww);
        }

        public function legacyDomainPattern(string $suffixPattern = '', bool $protocolOptional = true, bool $includeWww = true): string
        {
            return $this->buildDomainPattern($this->legacyDomains, $suffixPattern, $protocolOptional, $includeWww);
        }

        public function buildDoublePathPattern(string $pathSegment = '/product/'): string
        {
            $alternation = $this->currentDomainAlternation();
            if ($alternation === '') {
                return '/(?!)/';
            }

            $segment = '/' . ltrim($pathSegment, '/');
            $escapedSegment = preg_quote($segment, '/');

            return '/https?:\/\/(?:' . $alternation . ')' . $escapedSegment . '[^\'"\\s]*/i';
        }

        /**
         * @return array<int, string>
         */
        public function getLegacyHosts(bool $includeWww = true): array
        {
            return $this->buildHosts($this->legacyDomains, $includeWww);
        }

        /**
         * @return array<int, string>
         */
        public function getCurrentHosts(bool $includeWww = true): array
        {
            return $this->buildHosts([$this->currentDomain], $includeWww);
        }

        public function containsLegacyDomain(string $value): bool
        {
            $value = strtolower($value);
            foreach ($this->getLegacyHosts(true) as $host) {
                if ($host !== '' && strpos($value, strtolower($host)) !== false) {
                    return true;
                }
            }
            return false;
        }

        public function countCurrentDoublePathMatches(string $value, string $pathSegment = '/product/'): int
        {
            $pattern = $this->buildDoublePathPattern($pathSegment);
            if ($pattern === '/(?!)/') {
                return 0;
            }

            if (!preg_match_all($pattern, $value, $matches)) {
                return 0;
            }

            return count($matches[0]);
        }

        /**
         * Build replacement map for legacy domains → current domain.
         *
         * @return array<string, string>
         */
        public function buildDomainReplacementMap(): array
        {
            if (!$this->hasLegacyDomains()) {
                return [];
            }

            $map = [];
            $targetNoSlash = 'https://' . $this->currentDomain;
            $targetWithSlash = $targetNoSlash . '/';
            $targetProtocolRelative = '//' . $this->currentDomain;
            $targetHost = $this->currentDomain;

            foreach ($this->legacyDomains as $legacy) {
                foreach ($this->buildDomainVariants($legacy) as $variant) {
                    $replacement = $targetHost;

                    if (stripos($variant, 'http://') === 0 || stripos($variant, 'https://') === 0) {
                        $replacement = $targetNoSlash;
                    } elseif (strpos($variant, '//') === 0) {
                        $replacement = $targetProtocolRelative;
                    }

                    $endsWithSlash = substr($variant, -1) === '/';
                    if ($endsWithSlash) {
                        if (stripos($replacement, 'http') === 0) {
                            $replacement = $targetWithSlash;
                        } elseif (strpos($replacement, '//') === 0) {
                            $replacement = rtrim($targetProtocolRelative, '/') . '/';
                        } else {
                            $replacement = rtrim($targetHost, '/') . '/';
                        }
                    }

                    $map[$variant] = $replacement;

                    // Add uppercase protocol variants for robustness
                    if (stripos($variant, 'http://') === 0 || stripos($variant, 'https://') === 0) {
                        $upperVariant = strtoupper(substr($variant, 0, strpos($variant, '://'))) . substr($variant, strpos($variant, '://'));
                        $map[$upperVariant] = $replacement;
                    }
                }
            }

            return $map;
        }

        /**
         * @param array<int, string> $domains
         */
        private function buildAlternation(array $domains, bool $includeWww): string
        {
            $alternatives = [];
            foreach ($domains as $domain) {
                $domain = $this->normalizeDomain((string)$domain);
                if ($domain === '') {
                    continue;
                }

                $escaped = preg_quote($domain, '/');
                $alternatives[] = $escaped;
                if ($includeWww) {
                    $alternatives[] = 'www\.' . $escaped;
                }
            }

            $alternatives = array_values(array_unique(array_filter($alternatives)));

            return implode('|', $alternatives);
        }

        /**
         * @param array<int, string> $domains
         */
        private function buildDomainPattern(array $domains, string $suffixPattern, bool $protocolOptional, bool $includeWww): string
        {
            $alternation = $this->buildAlternation($domains, $includeWww);
            if ($alternation === '') {
                return '/(?!)/';
            }

            $protocol = $protocolOptional ? '(?:https?:\/\/)?' : 'https?:\/\/';

            return '/' . $protocol . '(?:' . $alternation . ')' . $suffixPattern . '/i';
        }

        /**
         * @param array<int, string> $domains
         * @return array<int, string>
         */
        private function buildHosts(array $domains, bool $includeWww): array
        {
            $hosts = [];
            foreach ($domains as $domain) {
                $normalized = $this->normalizeDomain($domain);
                if ($normalized === '') {
                    continue;
                }
                $hosts[] = $normalized;
                if ($includeWww) {
                    $hosts[] = 'www.' . $normalized;
                }
            }

            return array_values(array_unique($hosts));
        }

        /**
         * @return array<int, string>
         */
        private function buildDomainVariants(string $domain): array
        {
            $normalized = $this->normalizeDomain($domain);
            if ($normalized === '') {
                return [];
            }

            $variants = [];
            foreach ([$normalized, 'www.' . $normalized] as $host) {
                $variants[] = $host;
                $variants[] = 'https://' . $host;
                $variants[] = 'http://' . $host;
                $variants[] = '//' . $host;
            }

            $variants = array_values(array_unique($variants));

            $withSlash = [];
            foreach ($variants as $variant) {
                $withSlash[] = $variant;
                if (substr($variant, -1) !== '/') {
                    $withSlash[] = $variant . '/';
                }
            }

            return array_values(array_unique($withSlash));
        }
    }
}

