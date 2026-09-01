import OrgEntityPage, { type OrgEntityConfig } from './OrgEntityPage'

const config: OrgEntityConfig = {
  endpoint: 'branches',
  titleKey: 'nav.branches',
  labelField: 'name',
  createPerm: 'branches.create',
  updatePerm: 'branches.update',
  archivePerm: 'branches.archive',
  countField: 'employees_count',
  fields: [
    { name: 'name', labelKey: 'org.name', required: true },
    { name: 'code', labelKey: 'org.code', required: true },
    { name: 'city', labelKey: 'org.city' },
    { name: 'country_code', labelKey: 'org.country' },
    { name: 'timezone', labelKey: 'org.timezone' },
    { name: 'is_headquarters', labelKey: 'org.is_headquarters', type: 'checkbox' },
    { name: 'description', labelKey: 'org.description', type: 'textarea' },
  ],
}

export default function Branches() {
  return <OrgEntityPage config={config} />
}
