{{-- FILE: resources/views/livewire/admin/admins.blade.php --}}
<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Admins')]
class extends Component {
    public ?int $editingId = null;
    public bool $showForm = false;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = AdminRole::Admin->value;
    public bool $isActive = true;

    /** @var array<int, string> Section keys this admin may access. */
    public array $permissions = [];

    public function mount(): void
    {
        // Managing admins is the highest-risk surface - Super Admin only.
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403, 'Only a Super Admin can manage admins.');

        $this->permissions = AdminRole::from($this->role)->defaultPermissions();
    }

    /** All assignable sections, for the permission checkboxes. */
    #[Computed]
    public function sections(): array
    {
        return Admin::SECTIONS;
    }

    /**
     * When the role changes on the form, reset the ticked permissions to that
     * role's preset (the Super Admin can then fine-tune).
     */
    public function updatedRole(string $value): void
    {
        $this->permissions = AdminRole::from($value)->defaultPermissions();
    }

    #[Computed]
    public function admins()
    {
        return Admin::orderBy('name')->get();
    }

    protected function rules(): array
    {
        $unique = \Illuminate\Validation\Rule::unique('admins', 'email');
        if ($this->editingId) {
            $unique = $unique->ignore($this->editingId);
        }

        return [
            'name'     => 'required|string|max:100',
            'email'    => ['required', 'email', 'max:150', $unique],
            'password' => $this->editingId ? 'nullable|string|min:8' : 'required|string|min:8',
            'role'     => 'required|in:' . implode(',', array_column(AdminRole::cases(), 'value')),
            'isActive' => 'boolean',
        ];
    }

    public function newAdmin(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $admin = Admin::findOrFail($id);
        $this->editingId = $admin->id;
        $this->name      = $admin->name;
        $this->email     = $admin->email;
        $this->password  = '';
        $this->role      = $admin->role->value;
        $this->isActive  = $admin->is_active;
        // Show the admin's effective sections (saved set, or the role preset
        // when nothing has been customised yet).
        $this->permissions = $admin->accessibleSections();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        // Super Admins always have full access, so their permission set is left
        // null (the model returns every section for them). Other roles save the
        // ticked sections, filtered to the known registry.
        $role = AdminRole::from($this->role);
        $permissions = $role === AdminRole::SuperAdmin
            ? null
            : array_values(array_intersect($this->permissions, array_keys(Admin::SECTIONS)));

        $payload = [
            'name'        => $this->name,
            'email'       => $this->email,
            'role'        => $this->role,
            'permissions' => $permissions,
            'is_active'   => $this->isActive,
        ];

        if (filled($this->password)) {
            $payload['password'] = Hash::make($this->password);
        }

        if ($this->editingId) {
            Admin::findOrFail($this->editingId)->update($payload);
            session()->flash('status', $this->name . ' updated.');
        } else {
            Admin::create($payload);
            session()->flash('status', $this->name . ' created.');
        }

        $this->resetForm();
        unset($this->admins);
    }

    public function toggleActive(int $id): void
    {
        $currentAdminId = auth()->guard('admin')->id();

        if ($id === $currentAdminId) {
            session()->flash('error', 'You cannot deactivate your own account.');
            return;
        }

        $admin = Admin::findOrFail($id);
        $admin->update(['is_active' => ! $admin->is_active]);
        unset($this->admins);
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId   = null;
        $this->name        = '';
        $this->email       = '';
        $this->password    = '';
        $this->role        = AdminRole::Admin->value;
        $this->permissions = AdminRole::Admin->defaultPermissions();
        $this->isActive    = true;
        $this->showForm    = false;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-slot:heading>Admins</x-slot:heading>
    <x-slot:subheading>Manage administrator accounts and their access roles.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30">{{ session('error') }}</div>
        @endif

        {{-- Header action --}}
        <div class="flex items-center justify-end">
            <button
                wire:click="newAdmin"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                Add admin
            </button>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-[11px] uppercase tracking-wider text-zinc-600 dark:bg-[#0c1a36] dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Admin</th>
                            <th class="px-5 py-3 font-semibold">Role</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="hidden px-5 py-3 font-semibold md:table-cell">Last login</th>
                            <th class="hidden px-5 py-3 font-semibold sm:table-cell">Joined</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-inset">
                        @forelse ($this->admins as $admin)
                            @php
                                $roleTone = match ($admin->role) {
                                    \App\Domain\Admin\Enums\AdminRole::SuperAdmin => 'purple',
                                    \App\Domain\Admin\Enums\AdminRole::Admin      => 'blue',
                                    \App\Domain\Admin\Enums\AdminRole::Moderator  => 'amber',
                                    default                                        => 'zinc',
                                };
                                $isSelf = auth()->guard('admin')->id() === $admin->id;
                            @endphp
                            <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-[#26416b]/40">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">
                                            {{ $admin->initials() }}
                                        </span>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">
                                                {{ $admin->name }}
                                                @if ($isSelf)
                                                    <span class="ml-1 text-[10px] font-normal text-zinc-400 dark:text-zinc-500">(you)</span>
                                                @endif
                                            </p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $admin->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$roleTone">{{ $admin->role->label() }}</x-admin.badge>
                                </td>
                                <td class="px-5 py-3">
                                    <button
                                        @if (!$isSelf) wire:click="toggleActive({{ $admin->id }})" @endif
                                        type="button"
                                        @class(['cursor-pointer' => !$isSelf, 'cursor-not-allowed opacity-60' => $isSelf])
                                        @if ($isSelf) title="You cannot deactivate your own account" @endif
                                    >
                                        <x-admin.badge :tone="$admin->is_active ? 'emerald' : 'zinc'">
                                            {{ $admin->is_active ? 'Active' : 'Inactive' }}
                                        </x-admin.badge>
                                    </button>
                                </td>
                                <td class="hidden whitespace-nowrap px-5 py-3 text-xs text-zinc-500 dark:text-zinc-400 md:table-cell">
                                    {{ $admin->last_login_at ? $admin->last_login_at->diffForHumans() : 'Never' }}
                                </td>
                                <td class="hidden whitespace-nowrap px-5 py-3 text-xs text-zinc-500 dark:text-zinc-400 sm:table-cell">
                                    {{ $admin->created_at->format('M j, Y') }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button
                                        wire:click="edit({{ $admin->id }})"
                                        type="button"
                                        class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25"
                                    >Edit</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No admins found</p>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Click "Add admin" to create the first one.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Create / edit modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeForm" class="absolute inset-0 bg-zinc-900/40"></div>
            <form wire:submit="save" class="relative max-h-[90vh] w-full max-w-lg overflow-hidden rounded-[12px] bg-white shadow-2xl flex flex-col dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit admin' : 'Add admin' }}</h3>
                    <button type="button" wire:click="closeForm" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Name</label>
                        <input wire:model="name" type="text" placeholder="Jane Smith" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        @error('name') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Email</label>
                        <input wire:model="email" type="email" placeholder="jane@example.com" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        @error('email') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">
                            Password {{ $editingId ? '(leave blank to keep current)' : '' }}
                        </label>
                        <input wire:model="password" type="password" placeholder="{{ $editingId ? 'Leave blank to keep current' : 'Minimum 8 characters' }}" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white" autocomplete="new-password">
                        @error('password') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Role</label>
                        @php
                            $roleOptions = collect(AdminRole::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
                        @endphp
                        <x-admin.select wire:model.live="role" :options="$roleOptions" />
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Picking a role pre-fills the access below; tick or untick any section to customise.</p>
                        @error('role') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Section access. Super Admins always have everything, so the
                         checklist only shows for Admin / Moderator. --}}
                    @if ($role === \App\Domain\Admin\Enums\AdminRole::SuperAdmin->value)
                        <div class="rounded-[12px] bg-blue-50 px-4 py-3 text-xs font-medium text-blue-700 ring-1 ring-blue-200 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30">
                            Super Admins have full, unrestricted access to every section.
                        </div>
                    @else
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Section access</label>
                            <div class="mt-2 grid grid-cols-1 gap-1.5 rounded-[12px] border border-zinc-200 p-3 sm:grid-cols-2 dark:border-zinc-700/60">
                                @foreach ($this->sections as $key => $label)
                                    <label class="flex items-center gap-2 rounded-[8px] px-2 py-1.5 transition-colors hover:bg-zinc-50 dark:hover:bg-[#26416b]">
                                        <input type="checkbox" value="{{ $key }}" wire:model="permissions" class="h-4 w-4 cursor-pointer accent-blue-600">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">The admin only sees and can open the sections you tick. Dashboard and their own account are always available.</p>
                        </div>
                    @endif

                    <label class="flex items-center gap-2">
                        <input wire:model="isActive" type="checkbox" class="h-4 w-4 cursor-pointer accent-blue-600">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Active</span>
                    </label>
                </div>

                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="closeForm" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create admin' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
