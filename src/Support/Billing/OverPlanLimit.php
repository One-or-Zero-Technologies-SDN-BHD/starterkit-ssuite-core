<?php

namespace Ssuite\Core\Support\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Editing a record the workspace's plan no longer covers (usage over the cap). */
class OverPlanLimit extends RuntimeException
{
    public function __construct(public readonly string $column, public readonly int $cap, public readonly int $used, public readonly string $label)
    {
        parent::__construct(__('Your plan allows :cap :label; you have :used. Remove some or upgrade to edit them.', [
            'cap' => number_format($cap), 'label' => $label, 'used' => number_format($used),
        ]));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'over_limit' => $this->column], 403);
        }

        return redirect()->back()->withInput()->with('error', $this->getMessage());
    }
}
