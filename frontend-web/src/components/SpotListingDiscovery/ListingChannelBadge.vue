<template>
  <span
    class="listing-channel"
    :class="[`is-${meta.tone}`, { 'is-compact': compact, 'is-light': light }]"
  >
    <b>{{ meta.productScopeText }}</b>
    <strong>{{ meta.channelText }}</strong>
    <em
      v-for="tag in extraTags"
      :key="tag.code"
    >{{ tag.text }}</em>
  </span>
</template>

<script>
import { listingMetadata } from "@/utils/spotListingDiscovery";

export default {
  name: "SpotListingChannelBadge",
  props: {
    value: {
      type: Object,
      default: null
    },
    compact: Boolean,
    light: Boolean
  },
  computed: {
    meta() {
      return listingMetadata(this.value);
    },
    extraTags() {
      return this.meta.tags
        .filter(tag => tag.code !== this.meta.channel);
    }
  }
};
</script>

<style lang="scss" scoped>
.listing-channel {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  max-width: 100%;
  color: #35dcff;

  b,
  strong,
  em {
    display: inline-flex;
    align-items: center;
    min-height: 21px;
    padding: 2px 7px;
    border: 1px solid rgba(53, 220, 255, 0.34);
    border-radius: 3px;
    background: rgba(53, 220, 255, 0.07);
    font-size: 12px;
    font-style: normal;
    line-height: 1.2;
    white-space: nowrap;
  }

  b { letter-spacing: 0.08em; }
  strong { color: #eefaff; font-weight: 700; }
  em { color: #8fb6c7; }

  &.is-zone { color: #ffc857; }
  &.is-zone b,
  &.is-zone strong,
  &.is-zone em {
    border-color: rgba(255, 200, 87, 0.4);
    background: rgba(255, 200, 87, 0.08);
  }
  &.is-onchain { color: #ba8cff; }
  &.is-onchain b,
  &.is-onchain strong,
  &.is-onchain em {
    border-color: rgba(186, 140, 255, 0.45);
    background: rgba(186, 140, 255, 0.09);
  }
  &.is-rwa { color: #ffb15f; }
  &.is-rwa b,
  &.is-rwa strong,
  &.is-rwa em {
    border-color: rgba(255, 177, 95, 0.46);
    background: rgba(255, 177, 95, 0.1);
  }
  &.is-source { color: #72c7ff; }
  &.is-source b,
  &.is-source strong,
  &.is-source em {
    border-color: rgba(114, 199, 255, 0.42);
    background: rgba(114, 199, 255, 0.08);
  }
  &.is-premarket { color: #ff8f70; }
  &.is-premarket b,
  &.is-premarket strong,
  &.is-premarket em {
    border-color: rgba(255, 143, 112, 0.45);
    background: rgba(255, 143, 112, 0.09);
  }
  &.is-launchpad { color: #29e59d; }
  &.is-launchpad b,
  &.is-launchpad strong,
  &.is-launchpad em {
    border-color: rgba(41, 229, 157, 0.4);
    background: rgba(41, 229, 157, 0.08);
  }
  &.is-unknown { color: #ff657b; }
  &.is-unknown b,
  &.is-unknown strong,
  &.is-unknown em {
    border-color: rgba(255, 101, 123, 0.46);
    background: rgba(255, 101, 123, 0.08);
  }

  &.is-light b,
  &.is-light strong,
  &.is-light em {
    border-color: #9fb3be;
    color: #1b3441;
    background: #edf3f6;
  }
  &.is-light.is-zone b,
  &.is-light.is-zone strong,
  &.is-light.is-zone em {
    border-color: #d5a035;
    color: #654707;
    background: #fff4d7;
  }
  &.is-light.is-onchain b,
  &.is-light.is-onchain strong,
  &.is-light.is-onchain em {
    border-color: #9a75d2;
    color: #503278;
    background: #f3ebff;
  }
  &.is-light.is-unknown b,
  &.is-light.is-unknown strong,
  &.is-light.is-unknown em {
    border-color: #d9586a;
    color: #7d1e2b;
    background: #ffe8ec;
  }

  &.is-compact {
    gap: 3px;
  }
  &.is-compact b,
  &.is-compact strong,
  &.is-compact em {
    min-height: 17px;
    padding: 1px 4px;
    font-size: 12px;
  }
}
</style>
