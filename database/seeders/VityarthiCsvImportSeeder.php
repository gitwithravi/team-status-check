<?php

namespace Database\Seeders;

use App\Models\DailyTask;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VityarthiCsvImportSeeder extends Seeder
{
    private const IMPORT_PATH = 'imports/vityarthi';

    public function run(): void
    {
        $users = $this->readCsv(database_path(self::IMPORT_PATH.'/users.csv'));
        $tasks = $this->readCsv(database_path(self::IMPORT_PATH.'/tasks.csv'));

        DB::transaction(function () use ($users, $tasks): void {
            $emails = collect($users)->pluck('email')->all();

            foreach ($users as $row) {
                User::query()->updateOrCreate(
                    ['email' => $row['email']],
                    [
                        'name' => $row['name'],
                        'password' => $row['password'],
                        'role' => $row['role'],
                        'active' => (bool) $row['active'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                    ],
                );
            }

            $userIdsByEmail = User::query()
                ->whereIn('email', $emails)
                ->pluck('id', 'email');

            DailyTask::query()
                ->whereIn('user_id', $userIdsByEmail->values())
                ->delete();

            $taskRows = collect($tasks)->map(function (array $row) use ($userIdsByEmail): array {
                $userId = $userIdsByEmail[$row['user_email']] ?? null;

                if (! $userId) {
                    throw new RuntimeException("No user found for {$row['user_email']}.");
                }

                if (! in_array($row['status'], DailyTask::STATUSES, true)) {
                    throw new RuntimeException("Invalid task status {$row['status']} for {$row['user_email']}.");
                }

                return [
                    'user_id' => $userId,
                    'work_date' => $row['work_date'],
                    'project_name' => $row['project_name'],
                    'title' => $row['title'],
                    'notes' => $row['notes'] !== '' ? $row['notes'] : null,
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            })->all();

            foreach (array_chunk($taskRows, 500) as $chunk) {
                DailyTask::query()->insert($chunk);
            }

            $this->command?->info(sprintf(
                'Imported %d Vityarthi users and %d tasks.',
                count($users),
                count($taskRows),
            ));
        });
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV file not readable: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);

            throw new RuntimeException("CSV file has no header row: {$path}");
        }

        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = array_combine($headers, array_slice(array_pad($values, count($headers), ''), 0, count($headers)));

            if ($row === false) {
                throw new RuntimeException("Unable to parse CSV row in {$path}.");
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
