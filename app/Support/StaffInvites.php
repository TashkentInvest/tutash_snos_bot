<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffInvite;

/** Admin paneldan xodim qo'shish: botda bor bo'lsa — darhol yangilanadi, bo'lmasa — taklif (botga kirganda qo'llanadi). */
class StaffInvites
{
    /** @return string natija matni (bildirishnoma uchun) */
    public static function add(string $phone, ?string $name, string $role, array $districtIds, ?int $userId, bool $hourlyCheckin = false): string
    {
        $phone = Staff::normalizePhone($phone);
        $hourlyCheckin = $role === Staff::ROLE_EMPLOYEE && $hourlyCheckin; // shartnoma xodimida rol bo'yicha doim yoqilgan
        $withDistricts = in_array($role, [Staff::ROLE_EMPLOYEE, Staff::ROLE_CADASTRE], true);
        $districtIds = $withDistricts ? array_map('intval', $districtIds) : [];

        // botda allaqachon bor — roli va tumanlari darhol yangilanadi
        if ($staff = Staff::where('phone', $phone)->whereNotNull('telegram_id')->first()) {
            $staff->update(['role' => $role, 'hourly_checkin' => $hourlyCheckin, 'full_name' => $name ?: $staff->full_name] + ($staff->isActive() ? [] : ['status' => Staff::ACTIVE, 'registered_at' => now()]));
            $withDistricts && $staff->assignDistricts($districtIds);
            // kadastr xodimiga davomat va soatlik qayd yuritilmaydi
            $role === Staff::ROLE_CADASTRE && $staff->update(['attendance_required' => false, 'is_global' => false]);
            AuditLog::record('staff.role_set', null, ['by' => $userId, 'staff' => $staff->id, 'role' => $role]);

            return "{$staff->full_name} botda bor — roli yangilandi.";
        }

        StaffInvite::updateOrCreate(['phone' => $phone], [
            'full_name' => $name, 'role' => $role, 'hourly_checkin' => $hourlyCheckin, 'district_ids' => $districtIds ?: null, 'created_by' => $userId, 'staff_id' => null, 'used_at' => null,
        ]);
        AuditLog::record('staff.invited', null, ['by' => $userId, 'phone' => $phone, 'role' => $role]);

        return 'Qo‘shildi. Shu raqam egasi botda /start bosib, telefonini yuborganda avtomatik tasdiqlanadi.';
    }

    /**
     * Ro'yxatdan o'tishda: shu telefon uchun taklif bo'lsa — rol, ism va tumanlar qo'llanadi, taklif ishlatilgan deb belgilanadi.
     * Xodimni faollashtiradi; qo'llanmagan bo'lsa false.
     */
    public static function applyOnRegistration(Staff $staff, ?string $phone): bool
    {
        $invite = StaffInvite::pendingFor($phone);
        if (! $invite) {
            return false;
        }

        $staff->update([
            'phone' => $phone, 'status' => Staff::ACTIVE, 'role' => $invite->role, 'hourly_checkin' => (bool) $invite->hourly_checkin, 'registered_at' => now(),
            'failed_attempts' => 0, 'is_global' => false, 'attendance_required' => true,
            'full_name' => $invite->full_name ?: $staff->full_name,
        ]);
        in_array($invite->role, [Staff::ROLE_EMPLOYEE, Staff::ROLE_CADASTRE], true)
            ? $staff->assignDistricts($invite->district_ids ?? [])
            : $staff->districts()->detach();
        $invite->role === Staff::ROLE_CADASTRE && $staff->update(['attendance_required' => false]);
        $invite->update(['staff_id' => $staff->id, 'used_at' => now()]);
        AuditLog::record('registration.invite', $staff->telegram_id, ['invite' => $invite->id, 'role' => $invite->role]);

        return true;
    }
}
