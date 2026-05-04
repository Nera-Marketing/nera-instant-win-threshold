<template>
  <div class="w-full py-6">
    <!-- Loading state - Skeleton with inline shimmer -->
    <div v-if="loading" class="space-y-5">
      <!-- Stats skeleton -->
      <div
        class="rounded-2xl bg-gradient-to-br from-amber-50 to-yellow-50 border-2 border-amber-200 p-6"
      >
        <div class="flex items-center justify-around gap-4">
          <div class="flex-1 flex flex-col items-center space-y-3">
            <div
              class="h-12 w-12 rounded-full bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
            <div
              class="h-3 w-20 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
            <div
              class="h-8 w-16 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
          </div>
          <div class="w-px h-20 bg-gray-200"></div>
          <div class="flex-1 flex flex-col items-center space-y-3">
            <div
              class="h-12 w-12 rounded-full bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
            <div
              class="h-3 w-20 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
            <div
              class="h-8 w-16 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
          </div>
        </div>
      </div>

      <!-- Card skeletons -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div v-for="i in 2" :key="i" class="rounded-2xl bg-surface border border-gray-100 p-5">
          <div class="flex items-center gap-4">
            <div
              class="w-28 h-28 shrink-0 rounded-xl bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
            ></div>
            <div class="flex-1 space-y-3">
              <div
                class="h-5 w-3/4 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
              ></div>
              <div
                class="h-4 w-1/2 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
              ></div>
              <div
                class="h-1.5 w-full rounded-full bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"
              ></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Error state - Icon in circle, softer colors -->
    <div
      v-else-if="error"
      class="instant-wins-error bg-red-50/50 border border-red-200/50 rounded-2xl p-8 text-center"
    >
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-100 mb-4">
        <span class="material-symbols-outlined text-red-500 text-4xl">error</span>
      </div>
      <h3 class="text-lg font-bold text-red-900 mb-2">Unable to load instant wins</h3>
      <p class="text-sm text-red-700 mb-4 max-w-md mx-auto">
        {{ error }}
      </p>
      <button
        @click="fetchInstantWins"
        class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition-colors shadow-sm hover:shadow-md"
      >
        <span class="material-symbols-outlined text-lg">refresh</span>
        Try Again
      </button>
    </div>

    <!-- No data state -->
    <div
      v-else-if="!data || !data.prizes || data.prizes.length === 0"
      class="no-prizes-message bg-gray-50 border border-gray-200 rounded-2xl p-8 text-center"
    >
      <span class="material-symbols-outlined text-gray-400 text-5xl mb-3 block">inbox</span>
      <p class="text-text-secondary mb-0">No instant win prizes configured yet.</p>
    </div>

    <!-- Success state with data -->
    <template v-else>
      <!-- Stats Bar -->
      <StatsBar
        :available-count="data.stats?.availableCount || 0"
        :won-count="data.stats?.wonCount || 0"
        :available-label="data.stats?.availableLabel"
        :won-label="data.stats?.wonLabel"
      />

      <!-- Prizes Grid - gap-5 per plan -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-5 items-start">
        <PrizeCard
          v-for="(prize, index) in data.prizes"
          :key="prize.key || index"
          :prize="prize"
          :index="index"
          :on-show-all-winners="handleShowAllWinners"
        />
      </div>

      <!-- Winners Modal - Vue component -->
      <WinnersModal
        :is-open="modalState.isOpen"
        :on-close="handleCloseModal"
        :prize-title="modalState.prizeTitle"
        :winners="modalState.winners"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import StatsBar from './StatsBar.vue';
import PrizeCard from './PrizeCard.vue';
import WinnersModal from '../shared/WinnersModal.vue';

/**
 * Main Instant Wins Container (Vue)
 *
 * Responsibilities:
 * - Fetch data from REST API on mount
 * - Manage loading, error, and success states
 * - Render stats bar and prize grid
 * - Handle "See all winners" modal integration
 */
const props = defineProps({
  productId: {
    type: Number,
    required: true,
  },
});

const loading = ref(true);
const error = ref(null);
const data = ref(null);
const modalState = ref({
  isOpen: false,
  prizeTitle: '',
  winners: [],
});

const fetchInstantWins = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await fetch(`/wp-json/nera/v1/instant-wins/${props.productId}`);

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const result = await response.json();

    if (!result.success) {
      throw new Error(result.message || 'Failed to load instant wins');
    }

    // Transform API data to match component expectations
    // API returns: { title, image, total_available, won_count, winners }
    // Components expect: { prizeMessage, prizeImage, totalCount, wonCount, isWon, winners }
    const transformedData = {
      prizes: result.data.prizes.map(prize => ({
        key: prize.id,
        prizeMessage: prize.title, // API returns stripped text, not HTML
        prizeImage: prize.image
          ? `<img src="${prize.image}" alt="${prize.title}" class="w-full h-full object-cover" />`
          : null,
        totalCount: prize.total_available,
        wonCount: prize.won_count,
        isWon: prize.won_count > 0,
        winners: prize.winners || [],
      })),
      stats: {
        availableCount: result.data.stats.total_available - result.data.stats.total_won,
        wonCount: result.data.stats.total_won,
        availableLabel: `${result.data.stats.total_available - result.data.stats.total_won} Available`,
        wonLabel: `${result.data.stats.total_won} Won`,
      },
    };

    data.value = transformedData;
  } catch (err) {
    console.error('Error fetching instant wins:', err);
    error.value = err.message || 'Failed to load instant wins. Please try again.';
  } finally {
    loading.value = false;
  }
};

const handleShowAllWinners = (prizeTitle, winners) => {
  // Transform winners data to match modal expectations
  // Modal expects: { details, ticket_number }
  // API returns: { name, ticket, date }
  const transformedWinners = winners.map(winner => ({
    details: winner.name + (winner.date ? ' – ' + winner.date : ''),
    ticket_number: winner.ticket || '',
  }));

  modalState.value = {
    isOpen: true,
    prizeTitle,
    winners: transformedWinners,
  };
};

const handleCloseModal = () => {
  modalState.value = {
    isOpen: false,
    prizeTitle: '',
    winners: [],
  };
};

onMounted(() => {
  fetchInstantWins();
});
</script>
