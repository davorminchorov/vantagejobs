import * as React from "react"

import { cn } from "@/lib/utils"

interface NativeSelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  /** Classes for the outer inline-grid wrapper (width, margins). `className` goes on the <select>. */
  wrapperClassName?: string
  ref?: React.Ref<HTMLSelectElement>
}

function NativeSelect({ className, wrapperClassName, children, ref, ...props }: NativeSelectProps) {
  return (
    <div className={cn("inline-grid grid-cols-[1fr_--spacing(8)]", wrapperClassName)}>
      <select
        ref={ref}
        className={cn(
          "col-span-full row-start-1 h-10 w-full appearance-none rounded-md border border-input bg-background py-2 pr-8 pl-3 text-base text-foreground focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:text-sm",
          className
        )}
        {...props}
      >
        {children}
      </select>
      <svg
        viewBox="0 0 8 5"
        width="8"
        height="5"
        fill="none"
        aria-hidden="true"
        className="pointer-events-none col-start-2 row-start-1 place-self-center text-muted-foreground"
      >
        <path d="M.5.5 4 4 7.5.5" stroke="currentColor" />
      </svg>
    </div>
  )
}

export { NativeSelect }
export type { NativeSelectProps }
