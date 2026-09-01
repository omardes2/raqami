import OrgEntityPage, { type OrgEntityConfig } from './OrgEntityPage'

const config: OrgEntityConfig = {
  endpoint: 'teams',
  titleKey: 'nav.teams',
  labelField: 'name',
  createPerm: 'teams.create',
  updatePerm: 'teams.update',
  archivePerm: 'teams.archive',
  countField: 'members_count',
  fields: [
    { name: 'name', labelKey: 'org.name', required: true },
    { name: 'code', labelKey: 'org.code', required: true },
    { name: 'branch_id', labelKey: 'org.branch', type: 'select', optionsFrom: 'branches' },
    { name: 'department_id', labelKey: 'org.department', type: 'select', optionsFrom: 'departments' },
    { name: 'description', labelKey: 'org.description', type: 'textarea' },
  ],
}

export default function Teams() {
  return <OrgEntityPage config={config} />
}
