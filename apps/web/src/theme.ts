import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

/** Preset PrimeVue com a cor institucional do protótipo (teal #086b77). */
export const TmsaPreset = definePreset(Aura, {
  semantic: {
    primary: {
      50: '#e6f4f5',
      100: '#c3e4e6',
      200: '#9bd2d5',
      300: '#6fbec3',
      400: '#3fa3aa',
      500: '#13858e',
      600: '#086b77',
      700: '#075762',
      800: '#06454e',
      900: '#04333a',
      950: '#022227',
    },
  },
})
