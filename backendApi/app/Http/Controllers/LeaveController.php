<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveApplyRequest; // Ensure this class exists in the specified namespace
use App\Http\Requests\LeaveListRequest;
use App\Http\Requests\LeaveUpdateRequest;
use App\Models\Employee; // 確保引入 Employee 模型
use App\Models\LeaveType; // 確保引入 LeaveType 模型
use App\Services\LeaveService;
use App\Services\LeaveResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use App\Models\Leave;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use App\Models\Notification;
use Illuminate\Support\Facades\Http;


class LeaveController extends Controller
{
    protected $leaveService;
    protected $leaveResetService;

    public function __construct(LeaveService $leaveService, LeaveResetService $leaveResetService)
    {
        $this->leaveService = $leaveService;
        $this->leaveResetService = $leaveResetService;
    }

    // 1. 員工申請請假
    /**
     * @OA\Post(
     *     path="/api/leave/request",
     *     summary="請假申請",
     *     description="申請請假。",
     *     operationId="requestLeave",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"start_time", "end_time", "leave_type_id", "reason"},
     *                 @OA\Property(
     *                     property="start_time",
     *                     type="string",
     *                     format="date-time",
     *                     example="2025-03-14 09:00",
     *                     description="請假開始時間 （格式：YYYY-MM-DD HH:MM）"
     *                 ),
     *                 @OA\Property(
     *                     property="end_time",
     *                     type="string",
     *                     format="date-time",
     *                     example="2025-03-14 18:00",
     *                     description="請假結束時間 （格式：YYYY-MM-DD HH:MM）"
     *                 ),
     *                 @OA\Property(
     *                     property="leave_type_id",
     *                     type="integer",
     *                     example=1,
     *                     description="請假類型的ID"
     *                 ),
     *                 @OA\Property(
     *                     property="reason",
     *                     type="string",
     *                     example="需要休息",
     *                     description="請假原因"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment",
     *                     type="file",
     *                     format="binary",
     *                     nullable=true,
     *                     description="選擇要上傳的附件檔案"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="成功申請請假",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="申請成功，假單已送出"),
     *             @OA\Property(
     *                 property="leave",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="start_time", type="string", format="date-time", example="2025-03-14T09:00"),
     *                 @OA\Property(property="end_time", type="string", format="date-time", example="2025-03-14T18:00"),
     *                 @OA\Property(property="leave_type", type="string", example="Sick Leave"),
     *                 @OA\Property(property="status", type="integer", example=0),
     *                 @OA\Property(property="attachment", type="file", example="attachment/yourfilesname.jpg")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="無法申請生理假，使用者非女性",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="您無法申請生理假")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="伺服器錯誤，請檢查資料是否正確",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="申請失敗，請檢查填入資料是否有誤"),
     *             @OA\Property(property="error", type="string", nullable=true, example="資料格式錯誤")
     *         )
     *     )
     * )
     */
    public function requestLeave(LeaveApplyRequest $request): JsonResponse
    {
        try {
            // 1️⃣ 透過 JWT 取得當前登入者
            $user = auth()->user();
            $leaveType = LeaveType::find($request->input('leave_type_id'));

            // 2️⃣ **資料驗證**
            $data = $request->validated();
            $data['user_id'] = $user->id; // 由後端自動填入 `user_id`
            $data['status'] = 0;
            $data['start_time'] = $request->input('start_time');
            $data['attachment'] = null; // **預設 `attachment` 為 `null`，避免未定義錯誤**

            // 檢查時間重疊邏輯
            $startTime = $data['start_time'];
            $endTime = $data['end_time'];
            $isOverlap = Leave::where('user_id', $user->id)
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                })
                ->whereIn('status', [0, 1]) // 只檢查待審核或已通過的請假
                ->exists();

            if ($isOverlap) {
                throw new \Exception('您的請假時間與已有的請假紀錄重疊，請調整時間範圍後再重新申請。');
            }
            if ($data['start_time'] >= $data['end_time']) {
                return response()->json(['message' => '請假結束時間必須大於開始時間'], 422);
            }

            // **如果是假別是生理假，但使用者不是女性，則拒絕請假**
            if ($leaveType->name === 'Menstrual Leave' && $user->gender !== 'female') {
                return response()->json(['message' => '您無法申請生理假'], 403);
            }

            // 3️⃣ **預先檢查剩餘時數，減少 Service 負擔**
            $remainingHours = match ($leaveType->name) {
                'Annual leave' => $this->leaveResetService->getRemainingAnnualLeaveHours($user->id, $data['start_time']),
                'Menstrual Leave' => $this->leaveResetService->getRemainingMenstrualLeaveHours($user->id, $data['start_time']), // ✅ 傳入 `start_time`
                default => $this->leaveResetService->getRemainingLeaveHours($data['leave_type_id'], $user->id)
            };

            if ($remainingHours < $request->input('leave_hours')) {
                return response()->json([
                    'message' => "剩餘時數不足，請重新選擇請假區間",
                ], 400);
            }

            // 3️⃣ **處理附件**（如果沒有附件，`attachment` 保持 `null`）
            $fileRecord = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');

                // 產生唯一檔名
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $attachmentPath = $file->storeAs('attachments', $filename, 'public');

                // **存入 `files` 表**
                $fileRecord = File::create([
                    'user_id' => $user->id,
                    'leave_id' => null, // 先不關聯 `leave_id`，稍後再更新
                    'leave_attachment' => str_replace('public/', '', $attachmentPath), // ✅ 存成相對路徑
                ]);

                // **將附件 ID 存入 `$data`，傳遞給 Service**
                $data['attachment'] = $fileRecord->id;
            }

            // 4️⃣ **呼叫 Service 層處理請假**
            $leave = $this->leaveService->applyLeave($data);

            // 取得請假人員的主管 ID（透過 Employee 關聯）
            $employee = Employee::where('user_id', $user->id)->first();
            $managerId = $employee?->manager_id;

            if ($managerId) {
                $notification = Notification::create([
                    'user_id' => $managerId,
                    'title' => '部屬請假申請通知',
                    'message' => "{$user->name} 提出一筆請假申請，請前往審核",
                    'link' => '/approve/leave',
                    'archived' => false, // 明確指定未封存
                ]);

                Http::post('http://localhost:6001/notify', [
                    'id' => $notification->id,
                    'userId' => $managerId,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'link' => $notification->link,
                ]);
            }

            // 5️⃣ **如果有附件，更新 `leave_id` 到 `File` 表**
            if ($fileRecord) {
                $fileRecord->update(['leave_id' => $leave->id]);
            }

            // 6️⃣ **回傳成功資訊**
            return response()->json([
                'message' => '申請成功，假單已送出',
                'leave' => $this->formatLeave($leave),
            ], 201); // **201 Created：表示成功建立新資源**

        } catch (\Throwable $e) {
            // 7️⃣ **回傳錯誤資訊**
            return response()->json([
                'message' => $e->getMessage(),
                'error' => app()->isLocal() ? $e->getMessage() : null, // **本機開發環境才回傳錯誤**
            ], 500);
        }
    }

    // 2. 查詢個人請假紀錄 (員工)
    /**
     * @OA\Get(
     *     path="/api/leave/my-records",
     *     summary="查詢個人請假紀錄",
     *     description="員工查詢個人請假紀錄。",
     *     operationId="viewMyLeaveRecords",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="查詢起始日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-01"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="查詢結束日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-31"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="leave_type",
     *         in="query",
     *         required=false,
     *         description="請假類型 ID (必須為 leave_types 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=1
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="attachment",
     *         in="query",
     *         required=false,
     *         description="附件 ID (選填，必須為 files 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=10
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="審核狀態: 0:待審核, 1:主管通過, 2:主管拒絕, 3:HR同意, 4:HR拒絕",
     *         @OA\Schema(
     *             type="integer",
     *             enum={0,1,2,3,4},
     *             example=1
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功或查無符合條件的請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢成功"),
     *             @OA\Property(
     *                 property="records",
     *                 type="array",
     *                 description="請假紀錄列表",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2025-03-14T09:00:00"),
     *                     @OA\Property(property="end_time", type="string", format="date-time", example="2025-03-14T17:00:00"),
     *                     @OA\Property(property="leave_type", type="string", example="Sick Leave"),
     *                     @OA\Property(property="status", type="integer", example=0),
     *                     @OA\Property(property="attachment", type="string", example="attachment/yourfilesname.jpg")
     *                 )
     *             ),
     *             @OA\Property(property="total", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="無此權限，無法查詢請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="無此權限，無法查詢請假紀錄")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="查詢失敗",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢失敗")
     *         )
     *     )
     * )
     */
    public function viewMyLeaveRecords(LeaveListRequest $request): JsonResponse
    {
        $user = auth()->user();
        if ($user->employee->status === 'inactive') {
            return response()->json(['message' => '無此權限，無法查詢請假紀錄'], 403);
        }

        try {
            $filters = $request->validated();

            // Log::info('查詢請假紀錄', ['user_id' => $user->id, 'filters' => $filters]);

            $leaves = $this->leaveService->getLeaveList($user, $filters);

            if ($leaves->total() === 0) {            //這裡開始
                return response()->json([
                    'message' => '查無符合條件的請假紀錄',
                    'records' => [],
                    'total' => 0,
                ], 200);
            }
            return response()->json([
                'message' => '查詢成功',
                'records' => $leaves->map(fn($leave) => $this->formatLeave($leave)),
                'total' => $leaves->total(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => app()->isLocal() ? $e->getMessage() : '查詢失敗',
            ], 500);
        }
    }

    // 3. 查詢「部門」請假紀錄（主管 & HR）
    /**
     * @OA\Get(
     *     path="/api/leave/department",
     *     summary="查詢部門請假紀錄（主管 & HR）",
     *     description="主管或 HR 查詢部門內的請假紀錄",
     *     operationId="viewDepartmentLeaveRecords",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="leave_type_id",
     *         in="query",
     *         required=false,
     *         description="請假類型ID (選填，必須為 leave_types 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=1
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="employee_id",
     *         in="query",
     *         required=false,
     *         description="員工ID (選填，若要查詢特定員工的請假紀錄)",
     *         @OA\Schema(
     *             type="integer",
     *             example=5
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="查詢起始日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-01"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="查詢結束日期，必須大於或等於起始日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-31"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="審核狀態: 0:待審核, 1:主管通過, 2:主管拒絕, 3:HR同意, 4:HR拒絕",
     *         @OA\Schema(
     *             type="integer",
     *             enum={0,1,2,3,4},
     *             example=1
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="leave_hours",
     *         in="query",
     *         required=false,
     *         description="請假時數 (選填)",
     *         @OA\Schema(
     *             type="number",
     *             example=8
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="created_at",
     *         in="query",
     *         required=false,
     *         description="申請日期 (選填)",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-01"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功或查無符合條件的請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢成功"),
     *             @OA\Property(
     *                 property="records",
     *                 type="array",
     *                 description="請假紀錄列表",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2025-03-14T09:00:00"),
     *                     @OA\Property(property="end_time", type="string", format="date-time", example="2025-03-14T18:00:00"),
     *                     @OA\Property(property="leave_type", type="string", example="Sick Leave"),
     *                     @OA\Property(property="status", type="integer", example=0),
     *                     @OA\Property(property="attachment", type="string", example="attachment/yourfilesname.jpg")
     *                 )
     *             ),
     *             @OA\Property(property="total", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="無此權限，無法查詢請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="無此權限，無法查詢請假紀錄")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="查詢失敗",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢失敗")
     *         )
     *     )
     * )
     */
    public function viewDepartmentLeaveRecords(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->employee->status === 'inactive') {
            return response()->json(['message' => '無此權限，無法查詢請假紀錄'], 403);
        }

        try {
            $filters = $request->validate([
                'leave_type_id' => 'nullable|exists:leave_types,id',
                'employee_id' => 'nullable|exists:employees,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'status' => 'nullable|integer|in:0,1,2,3,4',
                'rejected' => 'nullable|integer',
                'leave_hours' => 'nullable',
                'created_at' => 'nullable|date',
            ]);

            // Log::info('查詢部門請假紀錄', ['user_id' => $user->id, 'filters' => $filters]);

            $leaves = $this->leaveService->getDepartmentLeaveList($user, $filters);

            if ($leaves->total() === 0) {
                return response()->json([
                    'message' => '查無符合條件的請假紀錄',
                    'records' => [],
                    'total' => 0,
                ], 200);
            }

            return response()->json([
                'message' => '查詢成功',
                'records' => $leaves->map(fn($leave) => $this->formatLeave($leave)),
                'total' => $leaves->total(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => app()->isLocal() ? $e->getMessage() : '查詢失敗',
            ], 500);
        }
    }

    // 4. 查詢「全公司」請假紀錄 (HR)
    /**
     * @OA\Get(
     *     path="/api/leave/company",
     *     summary="查詢全公司請假紀錄 (HR)",
     *     description="HR 查詢全公司的請假紀錄。",
     *     operationId="viewCompanyLeaveRecords",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="leave_type_id",
     *         in="query",
     *         required=false,
     *         description="請假類型ID (選填，必須為 leave_types 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=1
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="department_id",
     *         in="query",
     *         required=false,
     *         description="部門ID (選填，必須為 departments 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=2
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="employee_id",
     *         in="query",
     *         required=false,
     *         description="員工ID (選填，必須為 employees 表中的有效 id)",
     *         @OA\Schema(
     *             type="integer",
     *             example=5
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="查詢起始日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-01"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="查詢結束日期，必須大於或等於起始日期",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-31"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="審核狀態: 0:待審核, 1:主管通過, 2:主管拒絕, 3:HR同意, 4:HR拒絕",
     *         @OA\Schema(
     *             type="integer",
     *             enum={0,1,2,3,4},
     *             example=1
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="leave_hours",
     *         in="query",
     *         required=false,
     *         description="請假時數 (選填)",
     *         @OA\Schema(
     *             type="number",
     *             example=8
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="created_at",
     *         in="query",
     *         required=false,
     *         description="申請日期 (選填)",
     *         @OA\Schema(
     *             type="string",
     *             format="date",
     *             example="2025-03-01"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功或查無符合條件的請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢成功"),
     *             @OA\Property(
     *                 property="records",
     *                 type="array",
     *                 description="請假紀錄列表",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2025-03-14T09:00:00"),
     *                     @OA\Property(property="end_time", type="string", format="date-time", example="2025-03-14T17:00:00"),
     *                     @OA\Property(property="leave_type", type="string", example="Sick Leave"),
     *                     @OA\Property(property="status", type="integer", example=1),
     *                     @OA\Property(property="attachment", type="string", example="attachment/yourfilesname.jpg")
     *                 )
     *             ),
     *             @OA\Property(property="total", type="integer", example=100)
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="無此權限，無法查詢請假紀錄",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="無此權限，無法查詢請假紀錄")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="查詢失敗，請稍後再試",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查詢失敗，請稍後再試")
     *         )
     *     )
     * )
     */
    public function viewCompanyLeaveRecords(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user->employee->status === 'inactive') {
            return response()->json(['message' => '無此權限，無法查詢請假紀錄'], 403);
        }

        try {
            // ✅ 查詢所有請假紀錄
            $filters = $request->validate([
                'leave_type_id' => 'nullable|exists:leave_types,id',
                'department_id' => 'nullable|exists:departments,id',
                'employee_id' => 'nullable|exists:employees,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'status' => 'nullable|integer|in:0,1,2,3,4',
                'rejected' => 'nullable|integer',
                'leave_hours' => 'nullable',
                'created_at' => 'nullable|date',
            ]);

            $leaves = $this->leaveService->getCompanyLeaveList($filters);

            // 如果沒有任何符合條件的紀錄
            if ($leaves->total() === 0) {
                return response()->json([
                    'message' => '查無符合條件的請假紀錄',
                    'records' => [],
                    'total' => 0,
                ], 200);
            }

            // 有紀錄時的回應
            return response()->json([
                'message' => '查詢成功',
                'records' => $leaves->map(fn($leave) => $this->formatLeave($leave)),
                'total' => $leaves->total(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => app()->isLocal() ? $e->getMessage() : '查詢失敗，請稍後再試',
            ], 500);
        }
    }

    // 5. 修改請假申請 (HR、員工)
    /**
     * @OA\Post(
     *     path="/api/leave/update/{id}",
     *     summary="修改請假申請",
     *     description="HR 或員工可修改請假申請，生理假只能由女性申請。",
     *     operationId="updateLeave",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="假單 ID",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             example=123
     *         )
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"start_time", "end_time", "leave_type_id", "reason"},
     *                 @OA\Property(
     *                     property="leave_type_id",
     *                     type="integer",
     *                     description="請假類型 ID",
     *                     example=1
     *                 ),
     *                 @OA\Property(
     *                     property="start_time",
     *                     type="string",
     *                     format="date-time",
     *                     description="請假開始時間（格式：YYYY-MM-DD HH:MM）",
     *                     example="2025-03-14 09:00"
     *                 ),
     *                 @OA\Property(
     *                     property="end_time",
     *                     type="string",
     *                     format="date-time",
     *                     description="請假結束時間（格式：YYYY-MM-DD HH:MM，必須大於 `start_time`）",
     *                     example="2025-03-14 17:00"
     *                 ),
     *                 @OA\Property(
     *                     property="reason",
     *                     type="string",
     *                     description="請假原因",
     *                     example="調整休假時間"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment",
     *                     type="file",
     *                     format="binary",
     *                     nullable=true,
     *                     description="更新的附件檔案（選填，允許上傳最大 10MB 的 JPG、PNG、PDF 文件）"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="假單更新成功",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="假單更新成功"),
     *             @OA\Property(
     *                 property="leave",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="start_time", type="string", format="date-time", example="2025-03-14T09:00:00"),
     *                 @OA\Property(property="end_time", type="string", format="date-time", example="2025-03-14T17:00:00"),
     *                 @OA\Property(property="leave_type", type="string", example="Sick Leave"),
     *                 @OA\Property(property="reason", type="string", example="調整休假時間"),
     *                 @OA\Property(property="attachment", type="string", example="attachment/newfilename.jpg")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="查無此假單或無權限修改",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查無此假單或您無權限修改")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="無法申請生理假，使用者非女性",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="您無法申請生理假")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="請假結束時間必須大於開始時間",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="請假結束時間必須大於開始時間")
     *         )
     *     )
     * )
     */
    public function updateLeave(LeaveUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // 1️⃣ 取得請假紀錄
            $leave = Leave::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$leave) {
                return response()->json(['message' => '查無此假單或您無權限修改'], 403);
            }

            // 取得新的請假類型
            $newLeaveTypeId = $request->input('leave_type_id', $leave->leave_type_id);
            $leaveType = LeaveType::find($newLeaveTypeId);

            // **檢查是否修改為生理假，且申請者必須是女性**
            if ($leaveType && $leaveType->name === 'Menstrual Leave' && $user->gender !== 'female') {
                return response()->json(['message' => '您無法申請生理假'], 400);
            }

            //  // 2️⃣ **資料驗證**
            $data = $request->validated();
            $data['start_time'] = $request->input('start_time');
            $data['end_time'] = $request->input('end_time');
            $data['leave_type_id'] = $request->input('leave_type_id', $leave->leave_type_id); // 預設為原本的假別

            // 檢查時間重疊邏輯
            $startTime = $data['start_time'];
            $endTime = $data['end_time'];

            $isOverlap = Leave::where('user_id', $user->id)
                ->where('id', '!=', $id) // 排除當前假單
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where('start_time', '<', $endTime)
                        ->where('end_time', '>', $startTime);
                })
                ->whereIn('status', [0, 1]) // 只檢查待審核或已通過的請假
                ->exists();

            if ($isOverlap) {
                throw new \Exception('您的請假時間與已有的請假紀錄重疊，請調整時間範圍後再重新申請。');
            }

            if ($data['start_time'] >= $data['end_time']) {
                return response()->json(['message' => '請假結束時間必須大於開始時間'], 422);
            }

            // 2️⃣ **處理附件**（如果沒有新附件，保持原本的 `attachment_id`）
            $fileRecord = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');

                // 產生唯一檔名
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $attachmentPath = $file->storeAs('attachments', $filename, 'public');

                // 取得舊附件
                $oldFile = File::find($leave->attachment);

                // **刪除舊附件檔案**
                if ($oldFile && Storage::exists($oldFile->leave_attachment)) {
                    Storage::delete($oldFile->leave_attachment);
                    Log::info("成功刪除舊附件: " . $oldFile->leave_attachment);
                }

                // **更新舊 `File` 紀錄，或新增新附件**
                if ($oldFile) {
                    $oldFile->update(['leave_attachment' => $attachmentPath]);
                    $fileRecord = $oldFile;
                } else {
                    $fileRecord = File::create([
                        'user_id' => $user->id,
                        'leave_id' => $leave->id,
                        'leave_attachment' => $attachmentPath,
                    ]);
                }
            }

            // 3️⃣ **呼叫 Service 層更新請假**
            $updatedLeave = $this->leaveService->updateLeave($leave, [
                'leave_type_id' => $request->input('leave_type_id'),
                'start_time' => $request->input('start_time'),
                'end_time' => $request->input('end_time'),
                'reason' => $request->input('reason'),
                'status' => $request->input('status'),
                'attachment' => $fileRecord ? $fileRecord->id : $leave->attachment,
            ], $user, $data['start_time']); // ✅ 修正傳遞 `start_time`

            // 4️⃣ **回傳成功訊息**
            return response()->json([
                'message' => '假單更新成功',
                'leave' => $this->formatLeave($updatedLeave),
            ], 200);
        } catch (\Exception $e) {
            // 5️⃣ **回傳錯誤訊息**
            return response()->json([
                'message' => app()->isLocal() ? $e->getMessage() : '更新失敗，請重新檢查資料格式是否錯誤',
            ], 500);
        }
    }


    // 6. 刪除請假申請 (HR、員工)
    /**
     * @OA\Delete(
     *     path="/api/leave/{id}",
     *     summary="刪除請假申請",
     *     description=" HR 或員工可刪除請假申請。",
     *     operationId="deleteLeave",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="請假申請的 ID",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             example=123
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="假單刪除成功",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="假單刪除成功")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="查無此假單或無權限刪除",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="查無此假單或您無權限刪除")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="系統發生錯誤，請稍後再試",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="系統發生錯誤，請稍後再試")
     *         )
     *     )
     * )
     */
    public function deleteLeave(int $id): JsonResponse
    {
        try {
            $user = auth()->user();  // 取得當前登入的使用者

            // 1️⃣ **取得請假紀錄**
            $leave = Leave::find($id);

            if (!$leave || $leave->user_id !== $user->id) {
                // Log::warning("刪除請假失敗 - 找不到假單", ['user_id' => $user->id, 'leave_id' => $id]);
                return response()->json(['message' => '查無此假單'], 403);
            }

            // **限制只能刪除「待審核」的假單**
            if ($leave->status !== 0) {
                return response()->json(['message' => '僅能刪除尚未審核的假單'], 403);
            }

            // 2️⃣ **刪除相關附件**
            if (!empty($leave->attachment)) {
                $file = File::find($leave->attachment);
                if ($file) {
                    $filePath = $file->leave_attachment;

                    // **刪除實體檔案**
                    if ($filePath && Storage::exists($filePath)) {
                        Storage::delete($filePath);
                        Log::info("成功刪除附件檔案: " . $filePath);
                    }

                    // **刪除 `files` 表中的紀錄**
                    $file->delete();
                    // Log::info("成功刪除 files 記錄", ['file_id' => $file->id]);
                }
            }

            // 3️⃣ **刪除請假申請**
            $leave->delete();
            // Log::info("成功刪除假單", ['user_id' => $user->id, 'leave_id' => $id]);

            // 4️⃣ **回傳成功訊息**
            return response()->json(['message' => '假單刪除成功'], 200);
        } catch (\Exception $e) {
            // 5️⃣ **回傳錯誤訊息**
            return response()->json([
                'message' => app()->isLocal() ? $e->getMessage() : '系統發生錯誤，請稍後再試',
            ], 500);
        }
    }

    // 7. 取得特殊假別剩餘小時數
    /**
     * @OA\Get(
     *     path="/api/leavetypes/hours/{leave_type_id}",
     *     summary="查詢特殊假別剩餘小時數",
     *     description="查詢假別的剩餘可用請假小時數。",
     *     operationId="getRemainingLeaveHours",
     *     tags={"Leave"},
     *     security={{ "bearerAuth": {} }},
     *     @OA\Parameter(
     *         name="leave_type_id",
     *         in="path",
     *         description="假別 ID (必須為有效的假別 ID)",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             example=5
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="取得剩餘時數成功",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="leave_type", type="string", example="Annual Leave"),
     *             @OA\Property(property="remaining_hours", type="number", example=5)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="請假類型無效",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="請假類型無效")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="系統發生錯誤",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Internal Server Error")
     *         )
     *     )
     * )
     */
    public function getRemainingLeaveHours(Request $request, int $leave_type_id): JsonResponse
    {
        try {
            $user = auth()->user(); // 取得當前用戶
            $startTime = $request->query('start_time');
            $excludeId = $request->query('exclude_id');

            // 先確保該假別存在，避免查詢無效 ID
            $leaveType = LeaveType::find($leave_type_id);
            if (!$leaveType) {
                return response()->json([
                    'message' => '請假類型無效',
                ], 400);
            }
            // ✅ **直接使用 Service 層的 getRemainingLeaveHours()**
            $remainingHours = $this->leaveService->getRemainingLeaveHours($leave_type_id, $user->id, $startTime, $excludeId);

            return response()->json([
                'leave_type' => $leaveType->name,
                'remaining_hours' => $remainingHours,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    // 8. 資料格式統一，讓回傳結果都長一樣 ✅    
    private function formatLeave($leave): array
    {
        return [
            'leave_id' => $leave->id,
            'user_id' => $leave->user_id,
            'user_name' => $leave->user->name,
            'leave_type_id' => $leave->leave_type_id,
            'leave_type' => optional($leave->leaveType)->name, // 確保讀取關聯名稱
            'leave_type_name' => optional($leave->leaveType)->description,
            'start_time' => $leave->start_time,
            'end_time' => $leave->end_time,
            'reason' => $leave->reason,
            'leave_hours' => $leave->leave_hours,
            'status' => $leave->status,
            'reject_reason' => $leave->reject_reason,
            'attachment' => $leave->file ? asset("storage/" . $leave->file->leave_attachment) : null,
            'created_at' => $leave->created_at,
        ];
    }

    // 9.部門主管審核通過
    /**
     * @OA\Patch(
     *     path="/api/leave/{id}/department/approve",
     *     summary="主管審核部門請假單",
     *     description="此 API 允許部門主管審核請假單，只有擁有 `approve_department_leave` 權限的主管才能執行。",
     *     tags={"Leave-review"},
     *     security={{ "bearerAuth": {} }},
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="請假單 ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="integer", example=1, description="審核狀態（1=主管批准）")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="成功審核請假單",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="假單已被主管審核，等待 HR 審核")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="未授權請求，使用者未登入",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="未授權請求，請先登入")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=403,
     *         description="權限不足或假單狀態錯誤",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="你沒有權限審核本部門請假單"),
     *                 @OA\Property(property="error", type="string", example="此假單已審核，無法修改")
     *             }
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="請假單或員工資料不存在",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="查無此請假單"),
     *                 @OA\Property(property="error", type="string", example="請假單存在，但查無該員工")
     *             }
     *         )
     *     )
     * )
     */
    public function approveDepartmentLeave(Request $request, $id)
    {
        $leave = Leave::find($id);
        if (!$leave) {
            return response()->json(['error' => '查無此請假單'], 404);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => '未授權請求，請先登入'], 401);
        }

        // 確保使用者擁有 `approve_department_leave` 權限
        if (!$user->can('approve_department_leave')) {
            return response()->json(['error' => '你沒有權限審核本部門請假單'], 403);
        }

        // 找出請假員工
        $leaveEmployee = Employee::where('user_id', $leave->user_id)->first();
        if (!$leaveEmployee) {
            return response()->json(['error' => '請假單存在，但查無該員工'], 404);
        }

        // 確保主管只能審核自己部門的員工
        $departmentId = Employee::where('user_id', $user->id)->value('department_id');
        if ($departmentId !== $leaveEmployee->department_id) {
            return response()->json(['error' => '你只能審核自己部門的員工'], 403);
        }

        // 確保請假單是待審核狀態
        if ($leave->status !== 0) {
            return response()->json(['error' => '此假單已審核，無法修改'], 403);
        }

        // 更新狀態為 主管批准
        $leave->status = 1;
        $leave->approved_by = $user->id;
        $leave->save();

        // 建立通知並取得資料（通知員工）
        $notification = Notification::create([
            'user_id' => $leave->user_id,
            'title' => '請假單已審核',
            'message' => '主管已通過你的請假單，等待 HR 審核',
            'link' => '/leave/and/inquiry/records',
            'archived' => false,
        ]);

        Http::post('http://localhost:6001/notify', [
            'id' => $notification->id,
            'userId' => $leave->user_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'link' => $notification->link,
        ]);

        // 通知 HR 群組
        $hrUsers = \App\Models\User::role('HR')->get();

        foreach ($hrUsers as $hr) {
            $hrNotification = Notification::create([
                'user_id' => $hr->id,
                'title' => '請假單待 HR 審核',
                'message' => "部門主管 {$user->name} 已核准 {$leave->user->name} 的請假單，請 HR 審核",
                'link' => '/approve/leave',
                'archived' => false,
            ]);

            Http::post('http://localhost:6001/notify', [
                'id' => $hrNotification->id,
                'userId' => $hr->id,
                'title' => $hrNotification->title,
                'message' => $hrNotification->message,
                'link' => $hrNotification->link,
            ]);
        }

        return response()->json(['message' => '假單已被主管審核，等待 HR 審核'], 200);
    }

    // 10.部門主管審核拒絕
    /**
     * @OA\Patch(
     *     path="/api/leave/{id}/department/reject",
     *     summary="主管駁回部門請假單",
     *     description="此 API 允許部門主管駁回請假單，只有擁有 `approve_department_leave` 權限的主管才能執行。",
     *     tags={"Leave-review"},
     *     security={{ "bearerAuth": {} }},
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="請假單 ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="reject_reason", type="string", example="假期已超過可核准天數", description="駁回原因，必填")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="成功駁回請假單",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="假單已被主管拒絕")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="請求格式錯誤，例如未填寫拒絕原因",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="請填寫拒絕原因")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="未授權請求，使用者未登入",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="未授權請求，請先登入")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=403,
     *         description="權限不足或請假單狀態錯誤",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="你沒有權限駁回請假單"),
     *                 @OA\Property(property="error", type="string", example="你只能拒絕自己部門的員工"),
     *                 @OA\Property(property="error", type="string", example="此假單已審核，無法修改")
     *             }
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="請假單或員工資料不存在",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="查無此請假單"),
     *                 @OA\Property(property="error", type="string", example="請假單存在，但查無該員工")
     *             }
     *         )
     *     )
     * )
     */
    public function rejectDepartmentLeave(Request $request, $id)
    {
        $leave = Leave::find($id);
        if (!$leave) {
            return response()->json(['error' => '查無此請假單'], 404);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => '未授權請求，請先登入'], 401);
        }

        // 確保使用者擁有 `approve_department_leave` 權限
        if (!$user->can('approve_department_leave')) {
            return response()->json(['error' => '你沒有權限駁回請假單'], 403);
        }

        // 找出請假員工
        $leaveEmployee = Employee::where('user_id', $leave->user_id)->first();
        if (!$leaveEmployee) {
            return response()->json(['error' => '請假單存在，但查無該員工'], 404);
        }

        // 確保主管只能審核自己部門的員工
        $departmentId = Employee::where('user_id', $user->id)->value('department_id');
        if ($departmentId !== $leaveEmployee->department_id) {
            return response()->json(['error' => '你只能拒絕自己部門的員工'], 403);
        }

        // 確保請假單是待審核狀態
        if ($leave->status !== 0) {
            return response()->json(['error' => '此假單已審核，無法修改'], 403);
        }

        // 確保拒絕理由存在且不是空字串
        $rejectReason = trim($request->input('reject_reason'));
        if (empty($rejectReason)) {
            return response()->json(['error' => '請填寫拒絕原因'], 400);
        }

        // 更新狀態為 主管拒絕
        $leave->status = 2;
        $leave->reject_reason = $rejectReason;
        $leave->approved_by = $user->id;
        $leave->save();

        // 建立通知並取得資料
        $notification = Notification::create([
            'user_id' => $leave->user_id,
            'title' => '請假單已被駁回',
            'message' => '主管已駁回你的請假單，請重新申請',
            'link' => '/leave/and/inquiry/records',
            'archived' => false,
        ]);

        // 發送 WebSocket 通知
        Http::post('http://localhost:6001/notify', [
            'id' => $notification->id,
            'userId' => $leave->user_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'link' => $notification->link,
        ]);

        return response()->json(['message' => '假單已被主管拒絕'], 200);
    }

    // 11.HR審核通過
    /**
     * @OA\Patch(
     *     path="/api/leave/{id}/approve",
     *     summary="HR 最終批准請假單",
     *     description="此 API 允許 HR 人員最終批准請假單，只有擁有 `approve_leave` 權限的使用者才能執行。",
     *     tags={"Leave-review"},
     *     security={{ "bearerAuth": {} }},
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="請假單 ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="成功批准請假單",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="假單已最終批准")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="未授權請求，使用者未登入",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="未授權請求，請先登入")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=403,
     *         description="權限不足或請假單狀態錯誤",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="你沒有權限最終批准假單"),
     *                 @OA\Property(property="error", type="string", example="此假單已被批准，不可重複審核"),
     *                 @OA\Property(property="error", type="string", example="請假單必須先經過主管審核")
     *             }
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="請假單不存在",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="請假單不存在")
     *         )
     *     )
     * )
     */
    public function approveLeave(Request $request, $id)
    {
        $leave = Leave::find($id);
        if (!$leave) {
            return response()->json(['error' => '請假單不存在'], 404);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => '未授權請求，請先登入'], 401);
        }

        // 確保使用者擁有 `approve_leave` 權限
        if (!$user->can('approve_leave')) {
            return response()->json(['error' => '你沒有權限最終批准假單'], 403);
        }

        // 假單如果已經由主管拒絕，就顯示 此假單主管已拒絕不可審核
        if ($leave->status === 2) {
            return response()->json(['error' => '此假單主管已拒絕不可審核'], 403);
        }

        // 確保請假單尚未被批准
        if ($leave->status === 3 || $leave->status === 4) {
            return response()->json(['error' => '此假單已被批准，不可重複審核'], 403);
        }

        // 確保請假單已經經過主管審核
        if ($leave->status !== 1) {
            return response()->json(['error' => '請假單必須先經過主管審核'], 403);
        }

        // 更新狀態為 HR 批准
        $leave->status = 3;
        $leave->approved_by = $user->id;
        $leave->save();

        // 建立通知並取得資料
        $notification = Notification::create([
            'user_id' => $leave->user_id,
            'title' => '請假單已批准',
            'message' => '人資已批准你的請假單,請到假單查詢頁面查看。',
            'link' => '/leave/and/inquiry/records',
            'archived' => false,
        ]);

        // 發送 WebSocket 通知
        Http::post('http://localhost:6001/notify', [
            'id' => $notification->id,
            'userId' => $leave->user_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'link' => $notification->link,
        ]);

        return response()->json(['message' => '假單已最終批准'], 200);
    }

    // 12.HR審核拒絕
    /**
     * @OA\Patch(
     *     path="/api/leave/{id}/reject",
     *     summary="HR 最終駁回請假單",
     *     description="此 API 允許 HR 最終駁回請假單，只有擁有 `approve_leave` 權限的使用者才能執行。",
     *     tags={"Leave-review"},
     *     security={{ "bearerAuth": {} }},
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="請假單 ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="reject_reason", type="string", example="請假時間過長，影響工作安排", description="駁回理由，必填")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="成功駁回請假單",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="假單已被 HR 拒絕")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="請求格式錯誤，例如未填寫拒絕原因",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="請填寫拒絕原因")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="未授權請求，使用者未登入",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="未授權請求，請先登入")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=403,
     *         description="權限不足或請假單狀態錯誤",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Property(property="error", type="string", example="你沒有權限駁回假單"),
     *                 @OA\Property(property="error", type="string", example="請假單必須先經過主管審核"),
     *                 @OA\Property(property="error", type="string", example="此假單已被拒絕")
     *             }
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="請假單不存在",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="請假單不存在")
     *         )
     *     )
     * )
     */
    public function rejectLeave(Request $request, $id)
    {
        $leave = Leave::find($id);
        if (!$leave) {
            return response()->json(['error' => '請假單不存在'], 404);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => '未授權請求，請先登入'], 401);
        }

        // 確保使用者擁有 `approve_leave` 權限
        if (!$user->can('approve_leave')) {
            return response()->json(['error' => '你沒有權限駁回假單'], 403);
        }

        // 假單如果已經由主管拒絕，就顯示 此假單主管已拒絕不可審核
        if ($leave->status === 2) {
            return response()->json(['error' => '此假單主管已拒絕不可審核'], 403);
        }

        // 確保請假單尚未被批准
        if ($leave->status === 3 || $leave->status === 4) {
            return response()->json(['error' => '此假單已被批准，不可重複審核'], 403);
        }

        // 確保請假單已經經過主管審核
        if ($leave->status !== 1) {
            return response()->json(['error' => '請假單必須先經過主管審核'], 403);
        }

        // 確保拒絕理由存在且非空白字串
        $rejectReason = trim($request->input('reject_reason'));
        if (empty($rejectReason)) {
            return response()->json(['error' => '請填寫拒絕原因'], 400);
        }

        // 更新狀態為 HR 拒絕
        $leave->status = 4;
        $leave->reject_reason = $rejectReason;
        $leave->approved_by = $user->id;
        $leave->save();

        // 建立通知並取得資料
        $notification = Notification::create([
            'user_id' => $leave->user_id,
            'title' => '請假單已被駁回',
            'message' => '人資已駁回你的請假單,請重新申請。',
            'link' => '/leave/and/inquiry/records',
            'archived' => false,
        ]);

        // 發送 WebSocket 通知
        Http::post('http://localhost:6001/notify', [
            'id' => $notification->id,
            'userId' => $leave->user_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'link' => $notification->link,
        ]);

        return response()->json(['message' => '假單已被 HR 拒絕'], 200);
    }
}
