// Field-personnel roles treated identically everywhere the frontend special-cases
// "Site Engineer" by role name. Keep this array in sync with the PHP-side
// User::SITE_ENGINEER_ROLES constant (app/Models/User.php) — add a new field-role
// name here and to that constant, not to individual components.
export const SITE_ENGINEER_ROLES = ['Site Engineers', 'Site Engineer', 'Engineers', 'Architect', 'Draftsman', 'Technician'];

export function isSiteEngineerRoleName(name) {
  return SITE_ENGINEER_ROLES.includes(name);
}
