import OrgEntityPage, { type OrgEntityConfig } from './OrgEntityPage'

const config: OrgEntityConfig = {
  endpoint: 'job-titles',
  titleKey: 'nav.job_titles',
  labelField: 'title',
  createPerm: 'job_titles.create',
  updatePerm: 'job_titles.update',
  archivePerm: 'job_titles.archive',
  countField: 'employees_count',
  fields: [
    { name: 'title', labelKey: 'org.title', required: true },
    { name: 'code', labelKey: 'org.code', required: true },
    { name: 'level', labelKey: 'org.level' },
    { name: 'description', labelKey: 'org.description', type: 'textarea' },
  ],
}

export default function JobTitles() {
  return <OrgEntityPage config={config} />
}
