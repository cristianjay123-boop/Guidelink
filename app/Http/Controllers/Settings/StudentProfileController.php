<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->ensureStudent($request);

        $profile = $request->user()->studentProfile;
        $personal = $profile?->personal ?? [];
        $academicDetails = $profile?->academicDetails() ?? [
            'course' => null,
            'yearLevel' => null,
        ];
        $personal['last_name'] ??= $profile?->last_name;
        $personal['first_name'] ??= $profile?->first_name;
        $personal['middle_name'] ??= $profile?->middle_name;
        $personal['course'] ??= $academicDetails['course'];
        $personal['year_level'] ??= $academicDetails['yearLevel'];
        $education = $profile?->education ?? [];
        $education['history_records'] ??= $this->legacyEducationHistory(
            $education['history'] ?? null,
        );
        unset($education['history']);
        $education['history_records'] = collect($education['history_records'])
            ->map(fn (array $record) => [
                'level' => (string) ($record['level'] ?? ''),
                'school' => (string) ($record['school'] ?? ''),
                'school_year' => (string) ($record['school_year'] ?? ''),
                'honors_awards' => (string) ($record['honors_awards'] ?? ''),
            ])
            ->all();
        $family = $profile?->family ?? [];
        $family['siblings_records'] = collect($family['siblings_records'] ?? [])
            ->map(fn (array $record) => [
                'name' => (string) ($record['name'] ?? ''),
                'age' => (string) ($record['age'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'education_grade_year' => (string) (
                    $record['education_grade_year']
                    ?? $record['educational_attainment']
                    ?? ''
                ),
                'school_office_workplace' => (string) (
                    $record['school_office_workplace']
                    ?? $record['occupation']
                    ?? ''
                ),
            ])
            ->all();

        return Inertia::render('settings/student-profile', [
            'photoUrl' => $profile?->photo_path ? route('student-profile.photo') : null,
            'profile' => [
                'personal' => $personal,
                'emergency_contact' => $profile->emergency_contact ?? [],
                'education' => $education,
                'health' => $profile->health ?? [],
                'interests' => $profile->interests ?? [],
                'family' => $family,
                'socioeconomic' => $profile->socioeconomic ?? [],
                'psychological_tests' => $profile->psychological_tests ?? [],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureStudent($request);

        $validated = $request->validate([
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'personal.last_name' => ['nullable', 'string', 'max:255'],
            'personal.first_name' => ['nullable', 'string', 'max:255'],
            'personal.middle_name' => ['nullable', 'string', 'max:255'],
            'personal.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'personal.age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'personal.sex' => ['nullable', 'string', 'max:40'],
            'personal.birthplace' => ['nullable', 'string', 'max:255'],
            'personal.religion' => ['nullable', 'string', 'max:120'],
            'personal.course' => ['nullable', 'string', 'max:160'],
            'personal.year_level' => ['nullable', 'string', 'max:60'],
            'personal.telephone' => ['nullable', 'string', 'max:40'],
            'personal.cellphone' => ['nullable', 'string', 'max:40'],
            'personal.home_address' => ['nullable', 'string', 'max:2000'],
            'personal.city_address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact.name' => ['nullable', 'string', 'max:255'],
            'emergency_contact.relationship' => ['nullable', 'string', 'max:120'],
            'emergency_contact.address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact.phone' => ['nullable', 'string', 'max:40'],
            'education.history' => ['nullable', 'string', 'max:10000'],
            'education.history_records' => ['nullable', 'array', 'max:20'],
            'education.history_records.*' => ['array:level,school,school_year,honors_awards'],
            'education.history_records.*.level' => ['nullable', 'string', 'max:120'],
            'education.history_records.*.school' => ['nullable', 'string', 'max:255'],
            'education.history_records.*.school_year' => ['nullable', 'string', 'max:120'],
            'education.history_records.*.honors_awards' => ['nullable', 'string', 'max:1000'],
            'education.desired_course_first' => ['nullable', 'string', 'max:255'],
            'education.desired_course_second' => ['nullable', 'string', 'max:255'],
            'health.illnesses_injuries' => ['nullable', 'string', 'max:3000'],
            'health.height' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'health.weight' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'health.allergies' => ['nullable', 'string', 'max:3000'],
            'health.last_doctor_consultation' => ['nullable', 'string', 'max:255'],
            'health.other_concerns' => ['nullable', 'string', 'max:3000'],
            'interests.hobbies' => ['nullable', 'string', 'max:3000'],
            'interests.talents_skills' => ['nullable', 'string', 'max:3000'],
            'interests.organizations' => ['nullable', 'string', 'max:5000'],
            'interests.religious_organization' => ['nullable', 'string', 'max:255'],
            'interests.religious_position' => ['nullable', 'string', 'max:255'],
            'interests.academic_organization' => ['nullable', 'string', 'max:255'],
            'interests.academic_position' => ['nullable', 'string', 'max:255'],
            'interests.sociocultural_organization' => ['nullable', 'string', 'max:255'],
            'interests.sociocultural_position' => ['nullable', 'string', 'max:255'],
            'interests.sports_activities' => ['nullable', 'string', 'max:3000'],
            'interests.cultural_literary_activities' => ['nullable', 'string', 'max:3000'],
            'interests.reading_interest' => ['nullable', 'string', 'max:3000'],
            'interests.places_visited' => ['nullable', 'string', 'max:3000'],
            'interests.subjects_liked' => ['nullable', 'string', 'max:2000'],
            'interests.subjects_disliked' => ['nullable', 'string', 'max:2000'],
            'interests.plans_after_college' => ['nullable', 'string', 'max:3000'],
            'family.father' => ['nullable', 'array'],
            'family.father.name' => ['nullable', 'string', 'max:255'],
            'family.father.age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'family.father.deceased_present' => ['nullable', 'boolean'],
            'family.father.deceased' => ['nullable', 'boolean'],
            'family.father.birthplace' => ['nullable', 'string', 'max:255'],
            'family.father.citizenship' => ['nullable', 'string', 'max:120'],
            'family.father.religion' => ['nullable', 'string', 'max:120'],
            'family.father.education' => ['nullable', 'string', 'max:255'],
            'family.father.occupation' => ['nullable', 'string', 'max:255'],
            'family.father.work_address' => ['nullable', 'string', 'max:1000'],
            'family.father.contact_number' => ['nullable', 'string', 'max:40'],
            'family.father.college_school_attended' => ['nullable', 'string', 'max:255'],
            'family.mother' => ['nullable', 'array'],
            'family.mother.name' => ['nullable', 'string', 'max:255'],
            'family.mother.age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'family.mother.deceased_present' => ['nullable', 'boolean'],
            'family.mother.deceased' => ['nullable', 'boolean'],
            'family.mother.birthplace' => ['nullable', 'string', 'max:255'],
            'family.mother.citizenship' => ['nullable', 'string', 'max:120'],
            'family.mother.religion' => ['nullable', 'string', 'max:120'],
            'family.mother.education' => ['nullable', 'string', 'max:255'],
            'family.mother.occupation' => ['nullable', 'string', 'max:255'],
            'family.mother.work_address' => ['nullable', 'string', 'max:1000'],
            'family.mother.contact_number' => ['nullable', 'string', 'max:40'],
            'family.mother.college_school_attended' => ['nullable', 'string', 'max:255'],
            'family.siblings_records' => ['nullable', 'array', 'max:30'],
            'family.siblings_records.*' => ['array:name,age,status,education_grade_year,school_office_workplace'],
            'family.siblings_records.*.name' => ['nullable', 'string', 'max:255'],
            'family.siblings_records.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'family.siblings_records.*.status' => ['nullable', 'string', 'max:120'],
            'family.siblings_records.*.education_grade_year' => ['nullable', 'string', 'max:255'],
            'family.siblings_records.*.school_office_workplace' => ['nullable', 'string', 'max:255'],
            'family.siblings' => ['nullable', 'string', 'max:10000'],
            'family.living_arrangement' => ['nullable', 'string', 'max:255'],
            'family.living_arrangement_choice' => [
                'nullable',
                Rule::in([
                    'Own house',
                    'Rented apartment/boarding house',
                    'Relatives/grandparents/guardian',
                    'Other',
                ]),
            ],
            'family.living_arrangement_other' => ['nullable', 'string', 'max:255'],
            'family.current_address' => ['nullable', 'string', 'max:2000'],
            'family.parents_marital_status' => ['nullable', 'string', 'max:120'],
            'family.parents_marital_status_choice' => [
                'nullable',
                Rule::in([
                    'Civil',
                    'Catholic Church',
                    'Other',
                    'Living together',
                    'Separated',
                ]),
            ],
            'family.parents_marital_status_other' => ['nullable', 'string', 'max:120'],
            'family.step_relations' => ['nullable', 'string', 'max:1000'],
            'family.stepmother' => ['nullable', 'boolean'],
            'family.stepfather' => ['nullable', 'boolean'],
            'family.step_brothers' => ['nullable', 'integer', 'min:0', 'max:30'],
            'family.step_sisters' => ['nullable', 'integer', 'min:0', 'max:30'],
            'socioeconomic.support_sources' => ['nullable', 'string', 'max:2000'],
            'socioeconomic.support_sources_selected' => ['nullable', 'array', 'max:12'],
            'socioeconomic.support_sources_selected.*' => [
                'string',
                Rule::in([
                    'Parents',
                    'Relatives',
                    'Intern',
                    'Extern',
                    'Brothers / Sisters',
                    'Marillac Grantee',
                    'Vincent Fellow',
                    'SA',
                    'Employer',
                    'Scholarship',
                    'Academic Scholar',
                    'Other',
                ]),
            ],
            'socioeconomic.scholarships' => ['nullable', 'string', 'max:2000'],
            'socioeconomic.monthly_income_father' => [
                'nullable',
                Rule::in($this->familyIncomeRangeLabels()),
            ],
            'socioeconomic.monthly_income_mother' => [
                'nullable',
                Rule::in($this->familyIncomeRangeLabels()),
            ],
            'socioeconomic.monthly_income_siblings' => [
                'nullable',
                Rule::in($this->familyIncomeRangeLabels()),
            ],
            'socioeconomic.estimated_family_income' => ['nullable', 'string', 'max:120'],
            'socioeconomic.estimated_family_income_by_range' => [
                'nullable',
                'array:5000_below,5001_9000,9001_18000,18001_40000,40001_above',
            ],
            'socioeconomic.estimated_family_income_by_range.*' => [
                'nullable',
                'string',
                'max:120',
            ],
            'socioeconomic.specific_income' => ['nullable', 'string', 'max:255'],
            'socioeconomic.no_fixed_income' => ['nullable', 'string', 'max:255'],
            'socioeconomic.house_materials' => ['nullable', 'string', 'max:255'],
            'socioeconomic.house_materials_choice' => [
                'nullable',
                Rule::in(['Light', 'Strong', 'Mixed', 'Other']),
            ],
            'socioeconomic.house_materials_other' => ['nullable', 'string', 'max:255'],
            'socioeconomic.number_of_rooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'socioeconomic.appliances' => ['nullable', 'string', 'max:3000'],
            'socioeconomic.married_student' => ['nullable', 'array'],
            'socioeconomic.married_student.spouse_name' => ['nullable', 'string', 'max:255'],
            'socioeconomic.married_student.age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'socioeconomic.married_student.status' => ['nullable', 'string', 'max:120'],
            'socioeconomic.married_student.status_options_present' => ['nullable', 'boolean'],
            'socioeconomic.married_student.status_options' => ['nullable', 'array', 'max:3'],
            'socioeconomic.married_student.status_options.*' => [
                'string',
                Rule::in(['Civil', 'Church', 'Others']),
            ],
            'socioeconomic.married_student.deceased_present' => ['nullable', 'boolean'],
            'socioeconomic.married_student.deceased' => ['nullable', 'boolean'],
            'socioeconomic.married_student.years_married' => ['nullable', 'integer', 'min:0', 'max:100'],
            'socioeconomic.married_student.nationality' => ['nullable', 'string', 'max:120'],
            'socioeconomic.married_student.education' => ['nullable', 'string', 'max:255'],
            'socioeconomic.married_student.school_year_graduated' => ['nullable', 'string', 'max:120'],
            'socioeconomic.married_student.occupation' => ['nullable', 'string', 'max:255'],
            'socioeconomic.married_student.work_address' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('socioeconomic', $validated)) {
            $socioeconomic = $validated['socioeconomic'];

            if (array_key_exists('house_materials_choice', $socioeconomic)) {
                $socioeconomic['house_materials'] =
                    $socioeconomic['house_materials_choice'] === 'Other'
                        ? trim((string) ($socioeconomic['house_materials_other'] ?? ''))
                        : ($socioeconomic['house_materials_choice'] ?? '');
                unset(
                    $socioeconomic['house_materials_choice'],
                    $socioeconomic['house_materials_other'],
                );
            } else {
                $socioeconomic['house_materials'] = '';
            }

            if (array_key_exists(
                'status_options_present',
                $socioeconomic['married_student'] ?? [],
            )) {
                $marriedStudent = $socioeconomic['married_student'];
                $statusOptions = $marriedStudent['status_options'] ?? [];
                $marriedStudent['status_options'] = $statusOptions;
                $marriedStudent['status'] = implode(', ', $statusOptions);
                unset($marriedStudent['status_options_present']);
            }

            if (array_key_exists(
                'deceased_present',
                $socioeconomic['married_student'] ?? [],
            )) {
                $marriedStudent ??= $socioeconomic['married_student'];
                $marriedStudent['deceased'] = (bool) (
                    $marriedStudent['deceased'] ?? false
                );
                unset($marriedStudent['deceased_present']);
            }

            if (isset($marriedStudent)) {
                $socioeconomic['married_student'] = $marriedStudent;
            }
            $validated['socioeconomic'] = $socioeconomic;
        }

        $profile = $request->user()->studentProfile()->firstOrNew();

        foreach (['last_name', 'first_name', 'middle_name'] as $nameField) {
            $profile->{$nameField} = Arr::get(
                $validated,
                'personal.'.$nameField,
                $profile->{$nameField} ?? Arr::get(
                    $profile->personal ?? [],
                    $nameField,
                ),
            );
        }

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store('student-profiles/'.$request->user()->id, 'local');
            abort_if($newPhotoPath === false, 500, 'Unable to save the student profile photo.');

            if ($profile->photo_path) {
                Storage::disk('local')->delete($profile->photo_path);
            }

            $profile->photo_path = $newPhotoPath;
        }

        foreach ([
            'personal',
            'emergency_contact',
            'health',
            'interests',
            'family',
            'socioeconomic',
        ] as $section) {
            $profile->{$section} = Arr::get($validated, $section, []);
        }

        $family = $profile->family ?? [];
        foreach (['father', 'mother'] as $parent) {
            if (array_key_exists(
                'deceased_present',
                $family[$parent] ?? [],
            )) {
                $parentData = $family[$parent];
                $parentData['deceased'] = (bool) (
                    $parentData['deceased'] ?? false
                );
                unset($parentData['deceased_present']);
                $family[$parent] = $parentData;
            }
        }

        foreach ([
            'living_arrangement' => [
                'choice' => 'living_arrangement_choice',
                'other' => 'living_arrangement_other',
            ],
            'parents_marital_status' => [
                'choice' => 'parents_marital_status_choice',
                'other' => 'parents_marital_status_other',
            ],
        ] as $field => $inputs) {
            $choiceKey = $inputs['choice'];

            if (array_key_exists($choiceKey, $validated['family'] ?? [])) {
                $choice = $validated['family'][$choiceKey];
                $family[$field] = $choice === 'Other'
                    ? trim((string) Arr::get(
                        $validated,
                        'family.'.$inputs['other'],
                        '',
                    ))
                    : $choice;
                unset($family[$choiceKey], $family[$inputs['other']]);
            }
        }
        $profile->family = $family;

        if (array_key_exists('siblings_records', $validated['family'] ?? [])) {
            $family = $profile->family ?? [];
            $family['siblings_records'] = collect($validated['family']['siblings_records'])
                ->map(fn (array $record) => collect($record)
                    ->map(fn ($value) => is_string($value) ? trim($value) : $value)
                    ->all())
                ->filter(fn (array $record) => collect($record)
                    ->contains(fn ($value) => $value !== null && $value !== ''))
                ->values()
                ->all();
            $profile->family = $family;
        }

        $education = array_replace_recursive(
            $profile->education ?? [],
            Arr::get($validated, 'education', []),
        );

        if (array_key_exists('history_records', $validated['education'] ?? [])) {
            $education['history_records'] = collect($validated['education']['history_records'])
                ->map(fn (array $record) => collect($record)
                    ->map(fn ($value) => is_string($value) ? trim($value) : $value)
                    ->all())
                ->filter(fn (array $record) => collect($record)
                    ->contains(fn ($value) => $value !== null && $value !== ''))
                ->values()
                ->all();
            unset($education['history']);
        }

        $profile->education = $education;
        $profile->personal = Arr::except(
            $profile->personal ?? [],
            ['last_name', 'first_name', 'middle_name'],
        );

        $profile->save();

        $displayName = collect([
            $profile->first_name,
            $profile->middle_name,
            $profile->last_name,
        ])
            ->map(fn (?string $name) => trim((string) $name))
            ->filter()
            ->implode(' ');

        if ($displayName !== '') {
            $request->user()->name = $displayName;
            $request->user()->save();
        }

        return to_route('student-profile.edit')->with('status', 'Student profile saved.');
    }

    public function save(Request $request): RedirectResponse
    {
        return $this->update($request);
    }

    public function photo(Request $request): StreamedResponse
    {
        $this->ensureStudent($request);

        $photoPath = $request->user()->studentProfile?->photo_path;

        abort_unless($photoPath && Storage::disk('local')->exists($photoPath), 404);

        return Storage::disk('local')->response($photoPath, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensureStudent(Request $request): void
    {
        if ($request->user()->role !== UserRole::Student) {
            abort(403);
        }
    }

    /**
     * @return array<int, string>
     */
    private function familyIncomeRangeLabels(): array
    {
        return [
            '5,000 below',
            '5,001 - 9,000',
            '9,001 - 18,000',
            '18,001 - 40,000',
            '40,001 above',
        ];
    }

    /**
     * @return array<int, array{level: string, school: string, school_year: string, honors_awards: string}>
     */
    private function legacyEducationHistory(?string $history): array
    {
        $history = trim((string) $history);

        if ($history === '') {
            return [];
        }

        $parts = array_map('trim', explode('|', $history));

        if (count($parts) >= 4) {
            return [[
                'level' => $parts[0],
                'school' => $parts[1],
                'school_year' => $parts[2],
                'honors_awards' => implode(' | ', array_slice($parts, 3)),
            ]];
        }

        return [[
            'level' => '',
            'school' => $history,
            'school_year' => '',
            'honors_awards' => '',
        ]];
    }
}
