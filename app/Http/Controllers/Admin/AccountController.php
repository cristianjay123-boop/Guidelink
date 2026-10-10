<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accounts\ImportStudentAccounts;
use App\Actions\Accounts\ImportSubjectEnrollments;
use App\Actions\Accounts\ImportTeacherLoads;
use App\Actions\Accounts\UpsertManagedAccount;
use App\Actions\Accounts\UpsertTeacherLoads;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = User::query()
            ->with('studentProfile')
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'login_id', 'student_number', 'role', 'must_change_password'])
            ->map(function (User $user): array {
                $name = $user->name;

                if ($user->role === UserRole::Student && $user->studentProfile) {
                    $profile = $user->studentProfile;
                    $personal = $profile->personal ?? [];
                    $studentInformationName = collect([
                        $profile->first_name ?? ($personal['first_name'] ?? null),
                        $profile->middle_name ?? ($personal['middle_name'] ?? null),
                        $profile->last_name ?? ($personal['last_name'] ?? null),
                    ])
                        ->map(fn (?string $part) => trim((string) $part))
                        ->filter()
                        ->implode(' ');

                    if ($studentInformationName !== '') {
                        $name = $studentInformationName;
                    }
                }

                return [
                    'id' => $user->id,
                    'name' => $name,
                    'email' => $user->email,
                    'loginId' => $user->login_id,
                    'studentNumber' => $user->student_number,
                    'role' => $user->role->value,
                    'mustChangePassword' => $user->must_change_password,
                ];
            });

        return Inertia::render('admin/accounts', [
            'accounts' => $accounts,
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ]),
        ]);
    }

    public function store(Request $request, UpsertManagedAccount $upsert): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'student_number' => ['nullable', 'string', 'max:64'],
            'temporary_password' => ['nullable', 'string', 'min:12', 'max:255'],
        ]);

        $role = UserRole::from($validated['role']);
        $loginId = $upsert->loginIdFor($role, $validated['name'], $validated['student_number'] ?? null);
        $existing = User::query()->where('login_id', $loginId)->first();

        Validator::make($validated, [
            'email' => [Rule::unique(User::class, 'email')->ignore($existing?->id)],
            'student_number' => [
                Rule::requiredIf($role === UserRole::Student),
                'nullable',
                Rule::unique(User::class, 'student_number')->ignore($existing?->id),
            ],
        ])->validate();

        $upsert->handle(
            role: $role,
            name: $validated['name'],
            email: $validated['email'],
            studentNumber: $validated['student_number'] ?? null,
            temporaryPassword: $validated['temporary_password'] ?? null,
        );

        return to_route('admin.accounts')->with('status', 'Account saved.');
    }

    public function importStudents(Request $request, ImportStudentAccounts $importer): RedirectResponse
    {
        $validated = $request->validate([
            'student_file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
        ]);

        $result = $importer->import($validated['student_file']);

        return to_route('admin.accounts')->with(
            'status',
            "Student import complete: {$result['created']} created, {$result['updated']} updated.",
        );
    }

    public function importSubjectEnrollments(Request $request, ImportSubjectEnrollments $importer): RedirectResponse
    {
        $validated = $request->validate([
            'enrollment_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $result = $importer->import($validated['enrollment_file']);

        return to_route('admin.accounts')->with(
            'status',
            "Classlist import complete: {$result['created']} enrollments created, {$result['existing']} already existed.",
        );
    }

    public function importTeacherLoads(Request $request, ImportTeacherLoads $importer): RedirectResponse
    {
        $validated = $request->validate([
            'teacher_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $result = $importer->import($validated['teacher_file']);

        return to_route('admin.accounts')->with(
            'status',
            "Teacher upsert complete: {$result['created']} accounts created, {$result['updated']} updated, {$result['loads']} subject loads saved.",
        );
    }

    public function storeTeacherLoads(Request $request, UpsertTeacherLoads $upsert): RedirectResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.teacher_name' => ['required', 'string', 'max:255'],
            'records.*.email' => ['nullable', 'email', 'max:255'],
            'records.*.semester' => ['required', 'string', 'max:80'],
            'records.*.academic_year' => ['required', 'string', 'max:32'],
            'records.*.course_code' => ['nullable', 'string', 'max:80'],
            'records.*.descriptive_title' => ['required', 'string', 'max:255'],
            'records.*.course_year' => ['nullable', 'string', 'max:80'],
            'records.*.units' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ]);

        $result = $upsert->handle($validated['records']);

        return to_route('admin.accounts')->with(
            'status',
            "Teacher upsert complete: {$result['created']} accounts created, {$result['updated']} updated, {$result['loads']} subject loads saved.",
        );
    }

    public function studentImportTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, ['student_number']);
            fclose($output);
        }, 'student-accounts-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function subjectEnrollmentTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, ['course_code', 'student_number', 'semester', 'academic_year']);
            fclose($output);
        }, 'subject-code-classlist-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function teacherLoadTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'teacher_name',
                'email',
                'semester',
                'academic_year',
                'course_code',
                'descriptive_title',
                'course_year',
                'units',
            ]);
            fclose($output);
        }, 'teacher-subject-load-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function updateRole(Request $request, User $user, UpsertManagedAccount $upsert): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);
        $role = UserRole::from($validated['role']);

        if ($role === UserRole::Student && ! $user->student_number) {
            throw ValidationException::withMessages([
                'role' => 'Add a student number through the account form before assigning the Student role.',
            ]);
        }

        if ($user->role === UserRole::Superadmin && $role !== UserRole::Superadmin) {
            $superadminCount = User::query()->where('role', UserRole::Superadmin->value)->count();

            if ($superadminCount <= 1 || $user->is(auth()->user())) {
                throw ValidationException::withMessages([
                    'role' => 'The last Superadmin account cannot be demoted.',
                ]);
            }
        }

        $loginId = $upsert->loginIdFor($role, $user->name, $user->student_number);
        $loginIdInUse = User::query()
            ->where('login_id', $loginId)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($loginIdInUse) {
            throw ValidationException::withMessages([
                'role' => 'That role would give this account a login ID already in use.',
            ]);
        }

        $user->role = $role;
        $user->login_id = $loginId;

        if ($role !== UserRole::Student) {
            $user->student_number = null;
        }

        $user->save();

        return to_route('admin.accounts')->with('status', 'Role updated.');
    }
}
