// Fix: Removed file content markers that were causing syntax errors.
import React from 'react';
import Header from './components/Header';
import Hero from './components/Hero';
import Products from './components/Products';
import CommunityVetting from './components/CommunityVetting';
import Footer from './components/Footer';
import FeaturedProducts from './components/FeaturedProducts';
import Categories from './components/Categories';
import ParallaxAd from './components/ParallaxAd';
import TopSellers from './components/TopSellers';

const App: React.FC = () => {
  return (
    <div className="min-h-screen bg-white font-sans">
      <Header />
      <main>
        <Hero />
        <FeaturedProducts />
        <Products />
        <Categories />
        <ParallaxAd />
        <TopSellers />
        <CommunityVetting />
      </main>
      <Footer />
    </div>
  );
};

export default App;