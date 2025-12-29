import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Card } from "./Card.vue"
export { default as CardContent } from "./CardContent.vue"
export { default as CardDescription } from "./CardDescription.vue"
export { default as CardFooter } from "./CardFooter.vue"
export { default as CardHeader } from "./CardHeader.vue"
export { default as CardTitle } from "./CardTitle.vue"

export const cardVariants = cva(
  "bg-card text-card-foreground flex flex-col rounded-xl border shadow-sm",
  {
    variants: {
      size: {
        sm: "gap-3 py-0",
        md: "gap-6 py-6",
        lg: "gap-8 py-8",
      },
    },
    defaultVariants: {
      size: "md",
    },
  },
)

export const cardHeaderVariants = cva(
  "flex flex-col",
  {
    variants: {
      size: {
        sm: "gap-y-1 px-6 pt-6",
        md: "gap-y-1.5 p-6",
        lg: "gap-y-2 p-8",
      },
    },
    defaultVariants: {
      size: "md",
    },
  },
)

export const cardContentVariants = cva(
  "pt-0",
  {
    variants: {
      size: {
        sm: "px-6 pb-6",
        md: "p-6",
        lg: "p-8",
      },
    },
    defaultVariants: {
      size: "md",
    },
  },
)

export type CardVariants = VariantProps<typeof cardVariants>
export type CardHeaderVariants = VariantProps<typeof cardHeaderVariants>
export type CardContentVariants = VariantProps<typeof cardContentVariants>
