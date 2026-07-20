"use client"

import { useEffect } from "react"

export default function SyntheticV0PageForDeployment() {
  useEffect(() => {
    import("../assets/js/script")
  }, [])

  return (
    <div>
      <h1>Stock Exchange Pro</h1>
      <p>Welcome to the Stock Exchange Management System</p>
    </div>
  )
}
