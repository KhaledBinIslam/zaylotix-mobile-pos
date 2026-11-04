<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryAdvance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "keo joddhi running mase advance [nei] oder staf er oikhane
 * jate advance ta dekhai and r koita tk pabe dekhai. details e sob
 * dekhai koi tarik kon purpos advance niche." The staff list and the
 * payroll page both answer who owes what, advance by advance, with the
 * date and the purpose on each one.
 */
class SalaryAdvanceVisibilityTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function anEmployee(int $shopId): Employee
    {
        return Employee::create([
            'shop_id' => $shopId, 'name' => 'Rahim', 'salary_type' => 'monthly',
            'basic_salary' => 15000, 'joining_date' => now()->subYear()->toDateString(), 'status' => 'active',
        ]);
    }

    public function test_the_staff_list_carries_each_advance_with_date_and_purpose(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = $this->anEmployee($shop->id);

        SalaryAdvance::create([
            'shop_id' => $shop->id, 'employee_id' => $employee->id,
            'amount' => 3000, 'outstanding' => 3000, 'method' => 'cash',
            'note' => 'মেয়ের স্কুলের বেতন', 'date' => now()->toDateString(),
        ]);

        $this->actingAs($owner, 'web')->get(route('app.employees.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Employees/Index')
                ->where('employees.0.outstanding_advance', 3000)
                ->where('employees.0.advances.0.amount', 3000)
                ->where('employees.0.advances.0.note', 'মেয়ের স্কুলের বেতন')
                ->where('employees.0.advances.0.date', now()->format('d/m/Y'))
                ->etc());
    }

    public function test_an_old_unsettled_advance_still_shows_while_an_old_settled_one_does_not(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = $this->anEmployee($shop->id);

        SalaryAdvance::create([
            'shop_id' => $shop->id, 'employee_id' => $employee->id,
            'amount' => 2000, 'outstanding' => 500, 'method' => 'cash',
            'note' => 'গত মাসের ধার', 'date' => now()->subMonths(2)->toDateString(),
        ]);
        SalaryAdvance::create([
            'shop_id' => $shop->id, 'employee_id' => $employee->id,
            'amount' => 1000, 'outstanding' => 0, 'method' => 'cash',
            'note' => 'শোধ হয়ে গেছে', 'date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($owner, 'web')->get(route('app.employees.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employees.0.outstanding_advance', 500)
                ->count('employees.0.advances', 1)
                ->where('employees.0.advances.0.note', 'গত মাসের ধার')
                ->etc());
    }

    public function test_the_payroll_page_lists_the_running_months_advances_in_detail(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'hr_payroll');
        $employee = $this->anEmployee($shop->id);

        SalaryAdvance::create([
            'shop_id' => $shop->id, 'employee_id' => $employee->id,
            'amount' => 4000, 'outstanding' => 4000, 'method' => 'bank',
            'note' => 'চিকিৎসার খরচ', 'date' => now()->toDateString(),
        ]);

        $this->actingAs($owner, 'web')->get(route('app.payroll.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Employees/Payroll')
                ->where('rows.0.outstanding_advance', 4000)
                ->where('rows.0.advances.0.amount', 4000)
                ->where('rows.0.advances.0.note', 'চিকিৎসার খরচ')
                ->etc());
    }
}
