import OrgEntityPage, { type OrgEntityConfig } from './OrgEntityPage'

const config: OrgEntityConfig = {
  endpoint: 'departments',
  titleKey: 'nav.departments',
  labelField: 'name',
  createPerm: 'departments.create',
  updatePerm: 'departments.update',
  archivePerm: 'departments.archive',
  countField: 'employees_count',
  fields: [
    { name: 'name', labelKey: 'org.name', required: true },
    { name: 'code', labelKey: 'org.code', required: true },
    { name: 'branch_id', labelKey: 'org.branch', type: 'select', optionsFrom: 'branches' },
    { name: 'parent_department_id', labelKey: 'org.parent_department', type: 'select', optionsFrom: 'departments' },
    { name: 'description', labelKey: 'org.description', type: 'textarea' },
  ],
}

export default function Departments() {
  return <OrgEntityPage config={config} />
}
