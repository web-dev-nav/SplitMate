<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::post('/settlements', [ExpenseController::class, 'storeSettlement'])->name('settlements.store');
Route::get('/wallet-snapshots', [ExpenseController::class, 'getWalletSnapshots'])->name('wallet.snapshots');

// Statement History routes
Route::get('/statements/user/{userId}', [ExpenseController::class, 'userStatementView'])->name('statements.user');
Route::get('/api/statements/user/{userId}', [ExpenseController::class, 'apiStatementHistory'])->name('api.statements.user');
Route::get('/api/statements/all', [ExpenseController::class, 'getAllStatementHistory'])->name('api.statements.all');
Route::post('/api/statements/regenerate', [ExpenseController::class, 'regenerateStatementRecords'])->name('api.statements.regenerate');
Route::post('/api/statements/regenerate-simplified', [ExpenseController::class, 'regenerateSimplifiedStatements'])->name('api.statements.regenerate-simplified');
Route::get('/statements/regenerate', [ExpenseController::class, 'regenerateSimplifiedStatements'])->name('statements.regenerate');

// Debug routes
Route::get('/debug/balance', [ExpenseController::class, 'debugBalance'])->name('debug.balance');
Route::get('/debug/breakdowns', [ExpenseController::class, 'debugBreakdowns'])->name('debug.breakdowns');
Route::get('/debug/test-scenarios', [ExpenseController::class, 'testCalculationScenarios'])->name('debug.test-scenarios');
Route::get('/debug/validate-implementation', [ExpenseController::class, 'validateImplementation'])->name('debug.validate-implementation');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings/users', [SettingsController::class, 'updateUsers'])->name('settings.update-users');
Route::delete('/settings/users/{user}', [SettingsController::class, 'deleteUser'])->name('settings.delete-user');
Route::post('/settings/users/{user}/reactivate', [SettingsController::class, 'reactivateUser'])->name('settings.reactivate-user');

// Test storage configuration route
Route::get('/test-storage', function () {
    try {
        // Test if public disk is working
        $disk = Storage::disk('public');
        $testContent = 'Test file content - ' . now();
        $path = 'test-file.txt';

        // Try to put a file
        $success = $disk->put($path, $testContent);

        if ($success) {
            // Try to read it back
            $content = $disk->get($path);
            $exists = $disk->exists($path);

            // Clean up
            $disk->delete($path);

            return response()->json([
                'status' => 'success',
                'message' => 'Storage is working correctly',
                'disk_config' => [
                    'driver' => config('filesystems.disks.public.driver'),
                    'root' => config('filesystems.disks.public.root'),
                    'url' => config('filesystems.disks.public.url'),
                ],
                'test_results' => [
                    'put_successful' => $success,
                    'file_exists' => $exists,
                    'content_matches' => $content === $testContent,
                ]
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to write test file',
                'disk_config' => [
                    'driver' => config('filesystems.disks.public.driver'),
                    'root' => config('filesystems.disks.public.root'),
                ]
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Storage test failed: ' . $e->getMessage(),
            'exception' => get_class($e)
        ]);
    }
});

// Debug route to test expense upload
Route::post('/debug-expense-upload', function (Illuminate\Http\Request $request) {
    try {
        $data = [
            'request_method' => $request->method(),
            'has_files' => $request->hasFile('receipt_photo'),
            'all_files' => $request->allFiles(),
            'file_info' => null,
            'validation_errors' => null,
            'upload_result' => null
        ];

        if ($request->hasFile('receipt_photo')) {
            $file = $request->file('receipt_photo');
            $data['file_info'] = [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'is_valid' => $file->isValid(),
                'error' => $file->getError(),
                'error_message' => $file->getErrorMessage(),
                'max_file_size' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size')
            ];

            // Try validation
            try {
                $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                    'receipt_photo' => 'required|image|max:15360',
                ]);

                if ($validator->fails()) {
                    $data['validation_errors'] = $validator->errors()->toArray();
                } else {
                    // Try upload
                    try {
                        $path = $file->store('debug-test', 'public');
                        $data['upload_result'] = [
                            'success' => true,
                            'path' => $path,
                            'full_path' => storage_path('app/public/' . $path)
                        ];
                        // Clean up
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                    } catch (\Exception $e) {
                        $data['upload_result'] = [
                            'success' => false,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ];
                    }
                }
            } catch (\Exception $e) {
                $data['validation_errors'] = ['exception' => $e->getMessage()];
            }
        }

        return response()->json($data);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Debug failed: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// PHP configuration check
Route::get('/php-info', function () {
    return response()->json([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'file_uploads' => ini_get('file_uploads') ? 'enabled' : 'disabled',
        'max_file_uploads' => ini_get('max_file_uploads'),
        'comparison' => [
            'php_allows_uploads' => ini_get('file_uploads'),
            'php_upload_limit' => ini_get('upload_max_filesize'),
            'laravel_limit_kb' => 15360,
            'laravel_limit_mb' => round(15360/1024, 1)
        ]
    ]);
});

// Test file extensions validation
Route::post('/test-file-extension', function (Illuminate\Http\Request $request) {
    try {
        $data = [
            'request_info' => [
                'has_file' => $request->hasFile('test_file'),
                'all_files' => array_keys($request->allFiles()),
            ],
            'file_info' => null,
            'laravel_validation' => null,
            'mime_type_validation' => null
        ];

        if ($request->hasFile('test_file')) {
            $file = $request->file('test_file');

            $data['file_info'] = [
                'original_name' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'is_valid' => $file->isValid(),
                'error' => $file->getError(),
                'guessed_extension' => $file->guessExtension(),
            ];

            // Test Laravel's image validation
            try {
                $validator = \Illuminate\Support\Facades\Validator::make(['test_file' => $file], [
                    'test_file' => 'required|image|max:15360',
                ]);

                if ($validator->fails()) {
                    $data['laravel_validation'] = [
                        'passed' => false,
                        'errors' => $validator->errors()->toArray()
                    ];
                } else {
                    $data['laravel_validation'] = [
                        'passed' => true,
                        'message' => 'Laravel image validation passed'
                    ];
                }
            } catch (\Exception $e) {
                $data['laravel_validation'] = [
                    'error' => $e->getMessage()
                ];
            }

            // Test MIME type manually
            $validMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/svg+xml', 'image/webp'];
            $data['mime_type_validation'] = [
                'detected_mime' => $file->getMimeType(),
                'is_valid_mime' => in_array($file->getMimeType(), $validMimeTypes),
                'valid_mimes' => $validMimeTypes
            ];

            // Test different validation rules
            $validationTests = [
                'image_only' => 'image',
                'mimes_specific' => 'mimes:jpeg,jpg,png,gif,bmp,webp',
                'mimetypes_specific' => 'mimetypes:image/jpeg,image/png,image/gif,image/bmp,image/webp',
                'size_limit' => 'max:15360'
            ];

            $data['validation_tests'] = [];
            foreach ($validationTests as $test_name => $rule) {
                try {
                    $validator = \Illuminate\Support\Facades\Validator::make(['test_file' => $file], [
                        'test_file' => $rule,
                    ]);

                    $data['validation_tests'][$test_name] = [
                        'rule' => $rule,
                        'passed' => !$validator->fails(),
                        'errors' => $validator->fails() ? $validator->errors()->get('test_file') : null
                    ];
                } catch (\Exception $e) {
                    $data['validation_tests'][$test_name] = [
                        'rule' => $rule,
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        return response()->json($data);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Test failed: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

