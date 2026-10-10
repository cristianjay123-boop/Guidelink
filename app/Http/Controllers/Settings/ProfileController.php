<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profileName = $user->name;

        if ($user->role === UserRole::Student && $user->studentProfile) {
            $studentProfile = $user->studentProfile;
            $personal = $studentProfile->personal ?? [];
            $studentInformationName = collect([
                $studentProfile->first_name ?? ($personal['first_name'] ?? null),
                $studentProfile->middle_name ?? ($personal['middle_name'] ?? null),
                $studentProfile->last_name ?? ($personal['last_name'] ?? null),
            ])
                ->map(fn (?string $name) => trim((string) $name))
                ->filter()
                ->implode(' ');

            if ($studentInformationName !== '') {
                $profileName = $studentInformationName;
            }
        }

        return Inertia::render('settings/profile', [
            'profileName' => $profileName,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
