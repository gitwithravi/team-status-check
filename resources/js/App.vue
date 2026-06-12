<script setup>
import { computed, onMounted, reactive, ref } from 'vue';

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const user = ref(null);
const today = ref('');
const loading = ref(true);
const message = ref('');
const errors = ref({});
const view = ref('dashboard');

const loginForm = reactive({ email: '', password: '', remember: false });
const memberForm = reactive({ id: null, name: '', email: '', password: '', role: 'member', active: true });
const teamForm = reactive({ id: null, name: '', manager_id: '', member_ids: [] });
const taskForm = reactive({ id: null, project_name: '', title: '', notes: '', status: 'planned' });
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const filters = reactive({ date: '', member_id: '', status: '' });
const managerFilters = reactive({ date: '', status: '' });
const historyFilters = reactive({ date: '' });

const users = ref([]);
const members = ref([]);
const managers = ref([]);
const teams = ref([]);
const dashboard = ref([]);
const managerDashboard = ref([]);
const tasks = ref([]);
const taskHistory = ref([]);

const statusOptions = [
    { value: 'planned', label: 'Planned' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
    { value: 'blocked', label: 'Blocked' },
];

const isAdmin = computed(() => user.value?.role === 'admin');
const isMember = computed(() => user.value?.role === 'member');
const isTeamManager = computed(() => user.value?.role === 'team-manager');
const activeMembers = computed(() => members.value.filter((member) => member.active));
const activeManagers = computed(() => managers.value.filter((manager) => manager.active));

async function request(url, options = {}) {
    errors.value = {};
    message.value = '';

    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.headers || {}),
        },
        credentials: 'same-origin',
        ...options,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    if (response.status === 204) {
        return null;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        errors.value = data.errors || { general: [data.message || 'Something went wrong.'] };
        throw new Error(data.message || 'Request failed.');
    }

    return data;
}

async function loadSession() {
    try {
        const data = await request('/session');
        user.value = data.user;
        today.value = data.today;
        filters.date = filters.date || data.today;
        managerFilters.date = managerFilters.date || data.today;
        view.value = defaultView();
        await loadCurrentView();
    } catch {
        user.value = null;
    } finally {
        loading.value = false;
    }
}

async function login() {
    const data = await request('/login', {
        method: 'POST',
        body: loginForm,
    });

    user.value = data.user;
    today.value = data.today;
    filters.date = data.today;
    managerFilters.date = data.today;
    view.value = defaultView();
    loginForm.password = '';
    await loadCurrentView();
}

async function logout() {
    await request('/logout', { method: 'POST' });
    user.value = null;
    users.value = [];
    members.value = [];
    managers.value = [];
    teams.value = [];
    dashboard.value = [];
    managerDashboard.value = [];
    tasks.value = [];
    taskHistory.value = [];
}

function defaultView() {
    if (isAdmin.value) return 'dashboard';
    if (isTeamManager.value) return 'manager-dashboard';
    return 'tasks';
}

async function loadCurrentView() {
    if (isAdmin.value) {
        await Promise.all([loadMembers(), loadTeams(), loadDashboard()]);
    }

    if (isTeamManager.value) {
        await loadManagerDashboard();
    }

    if (isMember.value) {
        await loadTasks();
    }
}

async function loadMembers() {
    const data = await request('/admin/members');
    users.value = data.users || data.members;
    members.value = data.members;
    managers.value = data.managers || [];
}

async function saveMember() {
    const url = memberForm.id ? `/admin/members/${memberForm.id}` : '/admin/members';
    const method = memberForm.id ? 'PUT' : 'POST';
    const payload = { ...memberForm };

    if (memberForm.id && !payload.password) {
        delete payload.password;
    }

    await request(url, { method, body: payload });
    resetMemberForm();
    message.value = 'User saved.';
    await Promise.all([loadMembers(), loadTeams(), loadDashboard()]);
}

function editMember(member) {
    memberForm.id = member.id;
    memberForm.name = member.name;
    memberForm.email = member.email;
    memberForm.password = '';
    memberForm.role = member.role || 'member';
    memberForm.active = member.active;
    view.value = 'members';
}

function resetMemberForm() {
    memberForm.id = null;
    memberForm.name = '';
    memberForm.email = '';
    memberForm.password = '';
    memberForm.role = 'member';
    memberForm.active = true;
}

async function loadTeams() {
    const data = await request('/admin/teams');
    teams.value = data.teams;
}

async function saveTeam() {
    const url = teamForm.id ? `/admin/teams/${teamForm.id}` : '/admin/teams';
    const method = teamForm.id ? 'PUT' : 'POST';

    await request(url, {
        method,
        body: {
            name: teamForm.name,
            manager_id: teamForm.manager_id,
            member_ids: teamForm.member_ids,
        },
    });

    resetTeamForm();
    message.value = 'Team saved.';
    await loadTeams();
}

function editTeam(team) {
    teamForm.id = team.id;
    teamForm.name = team.name;
    teamForm.manager_id = team.manager_id || team.manager?.id || '';
    teamForm.member_ids = team.members.map((member) => member.id);
    view.value = 'teams';
}

function resetTeamForm() {
    teamForm.id = null;
    teamForm.name = '';
    teamForm.manager_id = '';
    teamForm.member_ids = [];
}

async function loadDashboard() {
    const params = new URLSearchParams();
    if (filters.date) params.set('date', filters.date);
    if (filters.member_id) params.set('member_id', filters.member_id);
    if (filters.status) params.set('status', filters.status);

    const data = await request(`/admin/dashboard?${params.toString()}`);
    dashboard.value = data.members;
}

async function loadManagerDashboard() {
    const params = new URLSearchParams();
    if (managerFilters.date) params.set('date', managerFilters.date);
    if (managerFilters.status) params.set('status', managerFilters.status);

    const data = await request(`/manager/dashboard?${params.toString()}`);
    managerDashboard.value = data.teams;
}

async function loadTasks() {
    const data = await request('/tasks');
    tasks.value = data.tasks;
    today.value = data.today;
}

async function loadTaskHistory() {
    const params = new URLSearchParams();
    if (historyFilters.date) params.set('date', historyFilters.date);

    const data = await request(`/tasks/history?${params.toString()}`);
    taskHistory.value = data.tasks;
    today.value = data.today;
}

async function clearHistoryFilters() {
    historyFilters.date = '';
    await loadTaskHistory();
}

async function saveTask() {
    const url = taskForm.id ? `/tasks/${taskForm.id}` : '/tasks';
    const method = taskForm.id ? 'PUT' : 'POST';

    await request(url, { method, body: taskForm });
    resetTaskForm();
    message.value = 'Task saved.';
    await loadTasks();
}

function editTask(task) {
    taskForm.id = task.id;
    taskForm.project_name = task.project_name || '';
    taskForm.title = task.title;
    taskForm.notes = task.notes || '';
    taskForm.status = task.status;
}

async function deleteTask(task) {
    await request(`/tasks/${task.id}`, { method: 'DELETE' });
    await loadTasks();
}

async function updatePassword() {
    await request('/password', {
        method: 'PUT',
        body: passwordForm,
    });

    resetPasswordForm();
    message.value = 'Password updated.';
}

function resetTaskForm() {
    taskForm.id = null;
    taskForm.project_name = '';
    taskForm.title = '';
    taskForm.notes = '';
    taskForm.status = 'planned';
}

function resetPasswordForm() {
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
}

function firstError(field) {
    return errors.value[field]?.[0] || '';
}

function statusLabel(status) {
    return statusOptions.find((item) => item.value === status)?.label || status;
}

function roleLabel(role) {
    if (role === 'team-manager') return 'Team manager';
    if (role === 'admin') return 'Admin';
    return 'Member';
}

onMounted(loadSession);
</script>

<template>
    <main class="min-h-screen bg-slate-50 text-slate-950">
        <section v-if="loading" class="flex min-h-screen items-center justify-center px-4">
            <div class="text-sm font-medium text-slate-600">Loading...</div>
        </section>

        <section v-else-if="!user" class="mx-auto flex min-h-screen w-full max-w-md items-center px-4">
            <form class="w-full rounded-lg border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="login">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold">Team Status</h1>
                    <p class="mt-1 text-sm text-slate-600">Sign in to update or review today's work.</p>
                </div>

                <label class="field">
                    <span>Email</span>
                    <input v-model="loginForm.email" type="email" autocomplete="email" required>
                    <small v-if="firstError('email')">{{ firstError('email') }}</small>
                </label>

                <label class="field">
                    <span>Password</span>
                    <input v-model="loginForm.password" type="password" autocomplete="current-password" required>
                    <small v-if="firstError('password')">{{ firstError('password') }}</small>
                </label>

                <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="loginForm.remember" type="checkbox" class="size-4 rounded border-slate-300">
                    Remember me
                </label>

                <button class="primary mt-6 w-full" type="submit">Sign in</button>
                <p v-if="firstError('general')" class="mt-3 text-sm text-red-600">{{ firstError('general') }}</p>
            </form>
        </section>

        <section v-else>
            <header class="border-b border-slate-200 bg-white">
                <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Team Status</h1>
                        <p class="text-sm text-slate-600">{{ user.name }} · {{ user.role }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="isAdmin" :class="['tab', view === 'dashboard' && 'active']" @click="view = 'dashboard'; loadDashboard()">Dashboard</button>
                        <button v-if="isAdmin" :class="['tab', view === 'members' && 'active']" @click="view = 'members'">Members</button>
                        <button v-if="isAdmin" :class="['tab', view === 'teams' && 'active']" @click="view = 'teams'; loadTeams()">Teams</button>
                        <button v-if="isTeamManager" :class="['tab', view === 'manager-dashboard' && 'active']" @click="view = 'manager-dashboard'; loadManagerDashboard()">Team Status</button>
                        <button v-if="isMember" :class="['tab', view === 'tasks' && 'active']" @click="view = 'tasks'; loadTasks()">Today</button>
                        <button v-if="isMember" :class="['tab', view === 'history' && 'active']" @click="view = 'history'; loadTaskHistory()">History</button>
                        <button :class="['tab', view === 'account' && 'active']" @click="view = 'account'">Account</button>
                        <button class="secondary" @click="logout">Logout</button>
                    </div>
                </div>
            </header>

            <div class="mx-auto max-w-7xl px-4 py-6">
                <p v-if="message" class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ message }}</p>

                <section v-if="isAdmin && view === 'dashboard'" class="space-y-5">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end">
                        <label class="field compact">
                            <span>Date</span>
                            <input v-model="filters.date" type="date" @change="loadDashboard">
                        </label>
                        <label class="field compact">
                            <span>Member</span>
                            <select v-model="filters.member_id" @change="loadDashboard">
                                <option value="">All members</option>
                                <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
                            </select>
                        </label>
                        <label class="field compact">
                            <span>Status</span>
                            <select v-model="filters.status" @change="loadDashboard">
                                <option value="">All statuses</option>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="member in dashboard" :key="member.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold">{{ member.name }}</h2>
                                    <p class="text-sm text-slate-500">{{ member.email }}</p>
                                </div>
                                <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                            </div>

                            <div class="mb-4 grid grid-cols-4 gap-2 text-center text-xs">
                                <div v-for="status in statusOptions" :key="status.value" class="rounded border border-slate-200 px-2 py-2">
                                    <div class="font-semibold">{{ member.counts[status.value] }}</div>
                                    <div class="text-slate-500">{{ status.label }}</div>
                                </div>
                            </div>

                            <div v-if="member.tasks.length" class="space-y-2">
                                <div v-for="task in member.tasks" :key="task.id" :class="['task-row', task.status === 'blocked' && 'blocked']">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                            <strong>{{ task.title }}</strong>
                                        </div>
                                        <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                                    </div>
                                    <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                </div>
                            </div>
                            <p v-else class="rounded border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500">No tasks for this date.</p>
                        </article>
                    </div>
                </section>

                <section v-if="isTeamManager && view === 'manager-dashboard'" class="space-y-5">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end">
                        <label class="field compact">
                            <span>Date</span>
                            <input v-model="managerFilters.date" type="date" @change="loadManagerDashboard">
                        </label>
                        <label class="field compact">
                            <span>Status</span>
                            <select v-model="managerFilters.status" @change="loadManagerDashboard">
                                <option value="">All statuses</option>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div v-for="team in managerDashboard" :key="team.id" class="space-y-3">
                        <div>
                            <h2 class="font-semibold">{{ team.name }}</h2>
                            <p class="text-sm text-slate-600">{{ team.members.length }} members</p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <article v-for="member in team.members" :key="member.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold">{{ member.name }}</h3>
                                        <p class="text-sm text-slate-500">{{ member.email }}</p>
                                    </div>
                                    <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                                </div>

                                <div class="mb-4 grid grid-cols-4 gap-2 text-center text-xs">
                                    <div v-for="status in statusOptions" :key="status.value" class="rounded border border-slate-200 px-2 py-2">
                                        <div class="font-semibold">{{ member.counts[status.value] }}</div>
                                        <div class="text-slate-500">{{ status.label }}</div>
                                    </div>
                                </div>

                                <div v-if="member.tasks.length" class="space-y-2">
                                    <div v-for="task in member.tasks" :key="task.id" :class="['task-row', task.status === 'blocked' && 'blocked']">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                                <strong>{{ task.title }}</strong>
                                            </div>
                                            <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                                        </div>
                                        <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                    </div>
                                </div>
                                <p v-else class="rounded border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500">No tasks for this date.</p>
                            </article>
                        </div>
                    </div>

                    <p v-if="!managerDashboard.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No teams assigned.</p>
                </section>

                <section v-if="isAdmin && view === 'members'" class="grid gap-6 lg:grid-cols-[360px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveMember">
                        <h2 class="mb-4 font-semibold">{{ memberForm.id ? 'Edit user' : 'Add user' }}</h2>
                        <label class="field">
                            <span>Name</span>
                            <input v-model="memberForm.name" required>
                            <small v-if="firstError('name')">{{ firstError('name') }}</small>
                        </label>
                        <label class="field">
                            <span>Email</span>
                            <input v-model="memberForm.email" type="email" required>
                            <small v-if="firstError('email')">{{ firstError('email') }}</small>
                        </label>
                        <label class="field">
                            <span>Role</span>
                            <select v-model="memberForm.role" required>
                                <option value="member">Member</option>
                                <option value="team-manager">Team manager</option>
                            </select>
                            <small v-if="firstError('role')">{{ firstError('role') }}</small>
                        </label>
                        <label class="field">
                            <span>Password</span>
                            <input v-model="memberForm.password" type="password" :required="!memberForm.id" minlength="8">
                            <small v-if="firstError('password')">{{ firstError('password') }}</small>
                        </label>
                        <label class="mb-4 flex items-center gap-2 text-sm text-slate-700">
                            <input v-model="memberForm.active" type="checkbox" class="size-4 rounded border-slate-300">
                            Active
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save user</button>
                            <button class="secondary" type="button" @click="resetMemberForm">Clear</button>
                        </div>
                    </form>

                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase text-slate-600">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in users" :key="member.id" class="border-b border-slate-100">
                                    <td class="px-4 py-3 font-medium">{{ member.name }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ member.email }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ roleLabel(member.role) }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button class="secondary" @click="editMember(member)">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="isAdmin && view === 'teams'" class="grid gap-6 lg:grid-cols-[380px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveTeam">
                        <h2 class="mb-4 font-semibold">{{ teamForm.id ? 'Edit team' : 'Add team' }}</h2>
                        <label class="field">
                            <span>Team name</span>
                            <input v-model="teamForm.name" required maxlength="255">
                            <small v-if="firstError('name')">{{ firstError('name') }}</small>
                        </label>
                        <label class="field">
                            <span>Manager</span>
                            <select v-model="teamForm.manager_id" required>
                                <option value="">Select manager</option>
                                <option v-for="manager in activeManagers" :key="manager.id" :value="manager.id">{{ manager.name }}</option>
                            </select>
                            <small v-if="firstError('manager_id')">{{ firstError('manager_id') }}</small>
                        </label>

                        <div class="mb-4">
                            <div class="mb-2 text-sm font-medium text-slate-700">Members</div>
                            <div class="max-h-72 space-y-2 overflow-auto rounded-md border border-slate-200 p-3">
                                <label v-for="member in activeMembers" :key="member.id" class="flex items-center gap-2 text-sm text-slate-700">
                                    <input v-model="teamForm.member_ids" type="checkbox" class="size-4 rounded border-slate-300" :value="member.id">
                                    <span>{{ member.name }}</span>
                                </label>
                                <p v-if="!activeMembers.length" class="py-6 text-center text-sm text-slate-500">No active members available.</p>
                            </div>
                            <small v-if="firstError('member_ids')" class="mt-1 block text-sm text-red-600">{{ firstError('member_ids') }}</small>
                        </div>

                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save team</button>
                            <button class="secondary" type="button" @click="resetTeamForm">Clear</button>
                        </div>
                    </form>

                    <div class="space-y-3">
                        <article v-for="team in teams" :key="team.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 class="font-semibold">{{ team.name }}</h2>
                                    <p class="text-sm text-slate-600">Manager: {{ team.manager?.name || 'Unassigned' }}</p>
                                    <p class="text-sm text-slate-500">{{ team.members.length }} members</p>
                                </div>
                                <button class="secondary" @click="editTeam(team)">Edit</button>
                            </div>
                            <div v-if="team.members.length" class="mt-3 flex flex-wrap gap-2">
                                <span v-for="member in team.members" :key="member.id" class="pill muted">{{ member.name }}</span>
                            </div>
                        </article>
                        <p v-if="!teams.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No teams created yet.</p>
                    </div>
                </section>

                <section v-if="isMember && view === 'tasks'" class="grid gap-6 lg:grid-cols-[380px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveTask">
                        <h2 class="font-semibold">{{ taskForm.id ? 'Edit today task' : 'Add today task' }}</h2>
                        <p class="mb-4 text-sm text-slate-600">{{ today }}</p>
                        <label class="field">
                            <span>Project Name</span>
                            <input v-model="taskForm.project_name" required maxlength="255">
                            <small v-if="firstError('project_name')">{{ firstError('project_name') }}</small>
                        </label>
                        <label class="field">
                            <span>Task</span>
                            <input v-model="taskForm.title" required maxlength="255">
                            <small v-if="firstError('title')">{{ firstError('title') }}</small>
                        </label>
                        <label class="field">
                            <span>Status</span>
                            <select v-model="taskForm.status" required>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                            <small v-if="firstError('status')">{{ firstError('status') }}</small>
                        </label>
                        <label class="field">
                            <span>Notes</span>
                            <textarea v-model="taskForm.notes" rows="4" maxlength="2000"></textarea>
                            <small v-if="firstError('notes')">{{ firstError('notes') }}</small>
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save task</button>
                            <button class="secondary" type="button" @click="resetTaskForm">Clear</button>
                        </div>
                    </form>

                    <div class="space-y-3">
                        <article v-for="task in tasks" :key="task.id" :class="['rounded-lg border bg-white p-4 shadow-sm', task.status === 'blocked' ? 'border-red-200' : 'border-slate-200']">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                    <h3 class="font-semibold">{{ task.title }}</h3>
                                    <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                </div>
                                <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                            </div>
                            <div class="mt-4 flex gap-2">
                                <button class="secondary" @click="editTask(task)">Edit</button>
                                <button class="danger" @click="deleteTask(task)">Delete</button>
                            </div>
                        </article>
                        <p v-if="!tasks.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No tasks added for today.</p>
                    </div>
                </section>

                <section v-if="isMember && view === 'history'" class="space-y-3">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Work history</h2>
                            <p class="text-sm text-slate-600">Past work is read-only.</p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <label class="field compact">
                                <span>Date</span>
                                <input v-model="historyFilters.date" type="date" @change="loadTaskHistory">
                            </label>
                            <button class="secondary" type="button" @click="clearHistoryFilters">Clear</button>
                        </div>
                    </div>

                    <article v-for="task in taskHistory" :key="task.id" :class="['rounded-lg border bg-white p-4 shadow-sm', task.status === 'blocked' ? 'border-red-200' : 'border-slate-200']">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500">{{ task.work_date }}</p>
                                <p v-if="task.project_name" class="mt-1 text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                <h3 class="mt-1 font-semibold">{{ task.title }}</h3>
                                <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                            </div>
                            <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                        </div>
                    </article>
                    <p v-if="!taskHistory.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No work history yet.</p>
                </section>

                <section v-if="view === 'account'" class="max-w-md">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="updatePassword">
                        <h2 class="mb-4 font-semibold">Update password</h2>
                        <label class="field">
                            <span>Current password</span>
                            <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required>
                            <small v-if="firstError('current_password')">{{ firstError('current_password') }}</small>
                        </label>
                        <label class="field">
                            <span>New password</span>
                            <input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" required>
                            <small v-if="firstError('password')">{{ firstError('password') }}</small>
                        </label>
                        <label class="field">
                            <span>Confirm new password</span>
                            <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                            <small v-if="firstError('password_confirmation')">{{ firstError('password_confirmation') }}</small>
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Update password</button>
                            <button class="secondary" type="button" @click="resetPasswordForm">Clear</button>
                        </div>
                    </form>
                </section>
            </div>
        </section>
    </main>
</template>
