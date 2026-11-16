<script setup>
import { ref, useId } from 'vue'
import Icon from './Icon.vue'

defineOptions({ inheritAttrs: false })
defineProps({ label: String })
const value = defineModel({ default: '' })
const visible = ref(false)
const id = useId()
</script>

<template>
  <div class="password-field">
    <label :for="id">{{ label }}</label>
    <div class="password-control">
      <input v-bind="$attrs" :id="id" v-model="value" :type="visible ? 'text' : 'password'" />
      <button class="password-toggle" type="button" :aria-label="visible ? 'Hide password' : 'Show password'" :aria-pressed="visible" :aria-controls="id" @click="visible = !visible">
        <Icon :name="visible ? 'eyeOff' : 'eye'" />
      </button>
    </div>
  </div>
</template>
