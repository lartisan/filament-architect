<?php

namespace Lartisan\Architect\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class CodePreview extends Component
{
    public function __construct(
        public string $code,
        public string $lang = 'php'
    ) {}

    public function render(): View
    {
        return view('architect::components.code-preview');
    }
}
