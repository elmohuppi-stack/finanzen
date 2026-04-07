function readConfiguredValue(value: string | undefined, fallback: string) {
  const normalized = value?.trim()
  const configured = normalized !== undefined && normalized.length > 0

  return {
    value: configured ? normalized : fallback,
    configured,
  }
}

const ownerName = readConfiguredValue(import.meta.env.VITE_LEGAL_NAME, 'Name in .env.local setzen')
const email = readConfiguredValue(import.meta.env.VITE_LEGAL_EMAIL, 'E-Mail in .env.local setzen')
const addressLine1 = readConfiguredValue(
  import.meta.env.VITE_LEGAL_ADDRESS_LINE_1,
  'Straße / Hausnummer in .env.local setzen',
)
const addressLine2 = readConfiguredValue(
  import.meta.env.VITE_LEGAL_ADDRESS_LINE_2,
  'PLZ / Ort in .env.local setzen',
)
const country = readConfiguredValue(import.meta.env.VITE_LEGAL_COUNTRY, 'Deutschland')
const contentResponsible = readConfiguredValue(
  import.meta.env.VITE_LEGAL_CONTENT_RESPONSIBLE,
  ownerName.value,
)

export const legalContact = {
  ownerName: ownerName.value,
  ownerNameConfigured: ownerName.configured,
  email: email.value,
  emailConfigured: email.configured,
  emailHref: email.configured ? `mailto:${email.value}` : '#',
  addressLine1: addressLine1.value,
  addressLine1Configured: addressLine1.configured,
  addressLine2: addressLine2.value,
  addressLine2Configured: addressLine2.configured,
  country: country.value,
  countryConfigured: country.configured,
  contentResponsible: contentResponsible.value,
  contentResponsibleConfigured: contentResponsible.configured,
  fullAddress: `${addressLine1.value}, ${addressLine2.value}`,
}
