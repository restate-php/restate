<?php

declare(strict_types=1);

namespace BoundaryFixture\Internal {
    /** @internal */
    final class Engine
    {
    }

    /** @internal */
    interface Port
    {
    }

    final class Untagged
    {
    }
}

namespace BoundaryFixture\Sub\Internal {
    /** @internal */
    final class Helper
    {
    }
}

namespace BoundaryFixture {
    use BoundaryFixture\Internal\Engine;
    use BoundaryFixture\Internal\Port;
    use BoundaryFixture\Sub\Internal\Helper;

    final class Clean
    {
        public const string NAME = 'clean';

        private function __construct(private Engine $engine, private readonly Helper $helper)
        {
        }

        public function name(): string
        {
            return self::NAME;
        }

        protected function hiddenByFinal(Engine $engine): Engine
        {
            return $engine;
        }

        /** @internal Listed as allowed. */
        public static function create(Engine $engine): self
        {
            return new self($engine, new Helper());
        }

        private function engine(): Engine
        {
            return $this->engine;
        }
    }

    class Leaky
    {
        public Engine $engine;

        /** @var list<Helper> */
        public array $helpers = [];

        public function __construct(Engine $engine)
        {
            $this->engine = $engine;
        }

        public function engine(): Engine
        {
            return $this->engine;
        }

        /** @return \Closure(Helper): void */
        public function callback(): \Closure
        {
            return static function (Helper $helper): void {
            };
        }

        protected function exposedBecauseNotFinal(?Port $port): void
        {
        }

        /** @internal Not listed. */
        public function unlisted(Engine $engine): void
        {
        }
    }

    interface Extended extends Port
    {
    }

    /** @internal Not listed. */
    final class HiddenInPublicNamespace
    {
    }

    /** @internal Listed as allowed; its API is still checked. */
    abstract class AllowedBase
    {
        abstract public function leak(): Engine;
    }
}
