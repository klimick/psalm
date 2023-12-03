<?php

namespace Psalm\Storage;

final class FunctionStorage extends FunctionLikeStorage
{
    /** @var array<string, bool> */
    public $byref_uses = [];

    public bool $is_anonymous = false;

    /**
     * @var bool
     * @todo lift this property to FunctionLikeStorage in Psalm 6
     */
    public $is_static = false;
}
