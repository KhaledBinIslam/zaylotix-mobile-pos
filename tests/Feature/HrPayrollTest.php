<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Payroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class HrPayrollTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_shop_without_the_feature_cannot_reach_any_hr_route(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // hr_payroll NOT granted

        $this->actingAs($owner, 'web')->get('/app/employees')->assertStatus(403);
    }

    public function test_a_cashier_cannot_reach_hr_routes_even_with_the_feature_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01800000010', 'password' => 'password', 'role' => 'staff', 'lang' => 'bn']);

        $this->actingAs($cashier, 'web')->get('/app/employees')->assertStatus(403);
    }

    public function test_owner_can_add_an_employee(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');

        $response = $this->actingAs($owner, 'web')->post('/app/employees', [
            'name' => 'Karim', 'phone' => '01711112222', 'designation' => 'Cashier',
            'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', ['name' => 'Karim', 'basic_salary' => 15000.00, 'status' => 'active']);
    }

    public function test_attendance_can_be_marked_and_defaults_to_present(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);

        $response = $this->actingAs($owner, 'web')->get('/app/attendance');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('employees.0.status', 'present'));

        $this->actingAs($owner, 'web')->post('/app/attendance', [
            'date' => now()->toDateString(), 'employee_id' => $employee->id, 'status' => 'absent',
        ])->assertRedirect();

        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'absent']);
    }

    public function test_marking_attendance_twice_for_the_same_day_updates_not_duplicates(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        $date = now()->toDateString();

        $this->actingAs($owner, 'web')->post('/app/attendance', ['date' => $date, 'employee_id' => $employee->id, 'status' => 'absent']);
        $this->actingAs($owner, 'web')->post('/app/attendance', ['date' => $date, 'employee_id' => $employee->id, 'status' => 'present']);

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
        $this->assertSame('present', Attendance::where('employee_id', $employee->id)->first()->status);
    }

    public function test_payroll_preview_deducts_a_per_day_share_for_a_monthly_employee(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web'); // Payroll::preview()'s queries are tenant-scoped and need a resolvable tenant
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 3000, 'joining_date' => '2026-01-01', 'status' => 'active']);
        // February 2026 has 28 days; 2 absences -> deduct 2/28 of 3000
        Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => '2026-02-05', 'status' => 'absent']);
        Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => '2026-02-10', 'status' => 'absent']);

        $preview = Payroll::preview($employee, '2026-02');

        $this->assertEqualsWithDelta(3000 - (3000 / 28 * 2), $preview['suggested_net'], 0.01);
        $this->assertSame(2, $preview['absent_days']);
    }

    public function test_payroll_preview_only_pays_marked_present_days_for_a_daily_employee(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Rina', 'salary_type' => 'daily', 'basic_salary' => 500, 'joining_date' => '2026-01-01', 'status' => 'active']);
        Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => '2026-03-01', 'status' => 'present']);
        Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => '2026-03-02', 'status' => 'present']);
        Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => '2026-03-03', 'status' => 'half_day']);

        $preview = Payroll::preview($employee, '2026-03');

        $this->assertEquals(2.5, $preview['present_days']);
        $this->assertEquals(1250.0, $preview['suggested_net']); // 500 * 2.5
    }

    public function test_giving_an_advance_decreases_cash_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post("/app/employees/{$employee->id}/advances", [
            'amount' => 2000, 'method' => 'cash',
        ])->assertRedirect();

        $this->assertEquals($startCash - 2000, (float) $shop->fresh()->cash_balance);
        $this->assertDatabaseHas('salary_advances', ['employee_id' => $employee->id, 'amount' => 2000.00, 'outstanding' => 2000.00]);
    }

    public function test_paying_salary_decreases_cash_balance_and_settles_advance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 50000]);
        $this->grantFeature($shop, 'hr_payroll');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        SalaryAdvance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'amount' => 3000, 'outstanding' => 3000, 'method' => 'cash', 'date' => now()->toDateString()]);
        $month = now()->format('Y-m');

        $response = $this->actingAs($owner, 'web')->post("/app/employees/{$employee->id}/salary", [
            'month' => $month, 'bonus' => 500, 'advance_deduction' => 3000, 'net_paid' => 12500, 'method' => 'cash',
        ]);

        $response->assertRedirect();
        $this->assertEquals(50000 - 12500, (float) $shop->fresh()->cash_balance);
        $this->assertDatabaseHas('salary_payments', ['employee_id' => $employee->id, 'month' => $month, 'net_paid' => 12500.00]);
        $this->assertEquals(0.0, (float) SalaryAdvance::first()->fresh()->outstanding);
    }

    public function test_cannot_pay_the_same_employee_the_same_month_twice(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 50000]);
        $this->grantFeature($shop, 'hr_payroll');
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Karim', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        $month = now()->format('Y-m');

        $this->actingAs($owner, 'web')->post("/app/employees/{$employee->id}/salary", [
            'month' => $month, 'net_paid' => 15000, 'method' => 'cash',
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->post("/app/employees/{$employee->id}/salary", [
            'month' => $month, 'net_paid' => 15000, 'method' => 'cash',
        ]);

        $response->assertSessionHasErrors('month');
        $this->assertSame(1, SalaryPayment::where('employee_id', $employee->id)->count());
        $this->assertEquals(50000 - 15000, (float) $shop->fresh()->cash_balance);
    }

    public function test_employees_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'hr_payroll');
        Employee::create(['shop_id' => $shopB->id, 'name' => 'Other Shop Employee', 'salary_type' => 'monthly', 'basic_salary' => 10000, 'joining_date' => now()->toDateString(), 'status' => 'active']);

        $response = $this->actingAs($ownerA, 'web')->get('/app/employees');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('employees', 0));
    }
}
