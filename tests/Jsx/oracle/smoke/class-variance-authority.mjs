import { cva } from "class-variance-authority";
const button = cva("btn", { variants: { size: { sm: "text-sm", lg: "text-lg" }, intent: { primary: "bg-blue" } }, defaultVariants: { size: "sm" } });
console.log(button(), button({ size: "lg", intent: "primary" }));
